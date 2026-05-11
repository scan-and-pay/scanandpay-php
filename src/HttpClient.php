<?php

declare(strict_types=1);

namespace ScanAndPay;

use ScanAndPay\Exceptions\ApiException;
use ScanAndPay\Exceptions\AuthenticationException;
use ScanAndPay\Exceptions\AuthorizationException;
use ScanAndPay\Exceptions\IdempotencyConflictException;
use ScanAndPay\Exceptions\NetworkException;
use ScanAndPay\Exceptions\NotFoundException;
use ScanAndPay\Exceptions\RateLimitException;
use ScanAndPay\Exceptions\ScanAndPayException;
use ScanAndPay\Exceptions\ServerException;

/**
 * HTTP transport with retry and structured error mapping.
 *
 * Retry policy (mirrors @scanandpay/node 0.2.0 with stricter PHP-side rules):
 *   - GET responses: retry on 5xx + network failures (GET is idempotent
 *     by HTTP definition).
 *   - POST responses: retry only when an `Idempotency-Key` header is
 *     present in `$extraHeaders`. Without it, mutating retries risk
 *     duplicate state — the brief mandates "never retry without
 *     idempotency key".
 *   - 4xx responses are surfaced immediately (mapped to typed exceptions).
 *     The brief explicitly forbids 4xx retries even for 408/429.
 *
 * Backoff:
 *   delay_ms = baseMs * 2 ** (attempt - 1)        (250, 500, 1000)
 *   jitter   = uniform[0, 0.5) * delay_ms          (full-half jitter)
 *   sleep    = (int) (delay_ms + jitter) * 1000    (μs)
 */
class HttpClient
{
    public const VERSION = '0.4.0';
    public const API_VERSION = '2026-05-11';
    public const DEFAULT_RETRIES = 3;
    public const DEFAULT_BASE_MS = 250;

    /** @var int[] HTTP status codes that should trigger a retry. */
    private const RETRYABLE_STATUS = [500, 502, 503, 504];

    public function __construct(
        private readonly string $apiSecret,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = self::DEFAULT_RETRIES,
        private readonly int $baseMs = self::DEFAULT_BASE_MS,
    ) {
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('apiSecret must not be empty');
        }
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('baseUrl must be a valid URL');
        }
    }

    /**
     * @param array<string, mixed>  $body          Sent as JSON.
     * @param array<string, string> $extraHeaders  Added on top of the defaults.
     *                                             Pass `Idempotency-Key` here to enable retry.
     * @return array<string, mixed>                Decoded JSON response.
     */
    public function post(string $path, array $body, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $body, [], $extraHeaders);
    }

    /**
     * @param array<string, scalar> $query         Appended as ?key=value pairs.
     * @param array<string, string> $extraHeaders  Added on top of the defaults.
     * @return array<string, mixed>                Decoded JSON response.
     */
    public function get(string $path, array $query = [], array $extraHeaders = []): array
    {
        return $this->request('GET', $path, null, $query, $extraHeaders);
    }

    /**
     * Hook for tests to short-circuit the actual sleep call. Override via
     * a subclass and capture sleep durations for assertions.
     */
    protected function sleepMicroseconds(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>     $query
     * @param array<string, string>     $extraHeaders
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        array $query = [],
        array $extraHeaders = []
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array_merge(
            [
                'X-Scanpay-Key' => $this->apiSecret,
                'Accept' => 'application/json',
                'User-Agent' => 'scanandpay-php/' . self::VERSION,
                'X-Scanpay-Sdk' => 'scanandpay-php/' . self::VERSION,
                'Scanpay-Version' => self::API_VERSION,
            ],
            $extraHeaders
        );

        $jsonBody = null;
        if ($method === 'POST' && $body !== null) {
            $jsonBody = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $headers['Content-Type'] = 'application/json';
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        // Brief rule: never retry mutating ops (POST) without an Idempotency-Key.
        // GETs are HTTP-idempotent and may always retry on transient failure.
        $retriesAllowed = $method === 'GET' || isset($extraHeaders['Idempotency-Key']);

        /** @var \Throwable|null $lastNetworkException */
        $lastNetworkException = null;
        $lastApiResponse = null;
        $lastStatusCode = 0;
        $maxAttempts = $retriesAllowed ? $this->retries + 1 : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $this->backoff($attempt - 1);
            }

            try {
                $result = $this->executeOnce($method, $url, $jsonBody, $headerLines);
                $statusCode = $result['statusCode'];
                $decoded = $result['decoded'];

                if (in_array($statusCode, self::RETRYABLE_STATUS, true) && $attempt < $maxAttempts) {
                    $lastApiResponse = $decoded;
                    $lastStatusCode = $statusCode;
                    continue;
                }

                return $this->handleResponse($statusCode, $decoded);
            } catch (NetworkException $e) {
                $lastNetworkException = $e;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                continue;
            }
        }

        // Exhausted retries on a 5xx — translate to a typed ServerException.
        if ($lastApiResponse !== null) {
            $message = $this->extractMessage($lastApiResponse, sprintf('HTTP %d after %d attempts', $lastStatusCode, $maxAttempts));
            throw new ServerException($message, $lastStatusCode, $lastApiResponse);
        }
        throw $lastNetworkException ?? new NetworkException('Request failed without a captured cause');
    }

    /**
     * Maps a single response to typed exceptions or returns the body.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function handleResponse(int $statusCode, array $decoded): array
    {
        $message = $this->extractMessage($decoded, sprintf('HTTP %d', $statusCode));

        if ($statusCode === 401) {
            throw new AuthenticationException($message, $decoded);
        }
        if ($statusCode === 403) {
            throw new AuthorizationException($message, $decoded);
        }
        if ($statusCode === 404) {
            throw new NotFoundException($message, $decoded);
        }
        if ($statusCode === 409) {
            throw new IdempotencyConflictException($message, $decoded);
        }
        if ($statusCode === 429) {
            throw new RateLimitException($message, $decoded);
        }
        if ($statusCode >= 500 && $statusCode < 600) {
            throw new ServerException($message, $statusCode, $decoded);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            // Other 4xx (400, 405, 422, ...) — bare ApiException.
            throw new ApiException($message, $statusCode, $decoded);
        }

        return $decoded;
    }

    private function backoff(int $retryNumber): void
    {
        // Exponential delay with full-half jitter:
        // delay   = baseMs * 2 ** (retryNumber - 1)
        // jitter  = uniform[0, 0.5) * delay
        $delayMs = $this->baseMs * (2 ** ($retryNumber - 1));
        // mt_rand returns int 0..mt_getrandmax(). Scale to [0, 0.5).
        $jitterFrac = mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax() * 0.5;
        $totalMs = (int) round($delayMs + ($delayMs * $jitterFrac));
        $this->sleepMicroseconds($totalMs * 1000);
    }

    /**
     * @param string[] $headerLines
     * @return array{statusCode: int, decoded: array<string, mixed>}
     */
    private function executeOnce(string $method, string $url, ?string $jsonBody, array $headerLines): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ScanAndPayException('Failed to initialise curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $errno !== 0) {
            throw new NetworkException(sprintf('Network error (%d): %s', $errno, $errstr));
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'statusCode' => $statusCode,
                'decoded' => ['raw' => substr((string) $rawResponse, 0, 512)],
            ];
        }

        return ['statusCode' => $statusCode, 'decoded' => $decoded];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractMessage(array $body, string $fallback): string
    {
        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }
        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }
        return $fallback;
    }
}
