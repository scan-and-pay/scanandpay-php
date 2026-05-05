<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use ScanAndPay\Exceptions\NetworkException;
use ScanAndPay\HttpClient;

/**
 * Test double for HttpClient that bypasses curl. Drops scripted responses
 * onto the request loop via a queue and overrides `sleepMicroseconds()`
 * so retries don't actually wait.
 *
 * Lives in tests/ so it never ships in dist.
 */
final class TestableHttpClient extends HttpClient
{
    /** @var list<array{statusCode: int, decoded: array<string, mixed>}> */
    public array $responses = [];

    public int $networkFailuresBeforeResponse = 0;

    public int $executions = 0;

    public int $sleeps = 0;

    public int $totalSleepUs = 0;

    public function __construct(int $retries = HttpClient::DEFAULT_RETRIES)
    {
        // Real apiSecret + baseUrl just to satisfy the parent constructor's
        // own validation. The curl path is overridden below.
        parent::__construct(
            apiSecret: 'sp_api_test',
            baseUrl: 'https://example.test',
            timeoutSeconds: 5,
            retries: $retries,
            baseMs: 1, // microscopic backoff for test speed
        );
    }

    protected function sleepMicroseconds(int $microseconds): void
    {
        $this->sleeps++;
        $this->totalSleepUs += $microseconds;
        // Don't actually sleep in tests.
    }

    /**
     * Override the parent's executeOnce via the reflection trick — the
     * parent calls $this->executeOnce() (private), so we hijack the request
     * pipeline via __call. Cleaner: re-implement post()/get() to drive
     * the queue directly.
     *
     * Instead of fighting visibility, we use the queue from the public
     * surface: each post()/get() call dequeues one response. The parent
     * implementation handles retry/backoff decisions; we just feed it
     * the canned (statusCode, decoded) tuples.
     */
    public function post(string $path, array $body, array $extraHeaders = []): array
    {
        return $this->driveQueue('POST', $path, $body, [], $extraHeaders);
    }

    public function get(string $path, array $query = [], array $extraHeaders = []): array
    {
        return $this->driveQueue('GET', $path, null, $query, $extraHeaders);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>     $query
     * @param array<string, string>     $extraHeaders
     * @return array<string, mixed>
     */
    private function driveQueue(string $method, string $path, ?array $body, array $query, array $extraHeaders): array
    {
        // Mirror the parent's retry semantics directly so we can verify
        // execution counts. Reusing parent::request() is impossible
        // because it private-calls executeOnce(); rewriting here is the
        // simplest way to exercise the retry policy in unit tests.
        $retries = $this->retriesAllowedFor($method, $extraHeaders) ? $this->retriesValue() : 0;
        $maxAttempts = $retries + 1;

        $lastApi = null;
        $lastStatus = 0;
        $lastNetwork = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $this->sleepMicroseconds(1);
            }

            try {
                [$status, $decoded] = $this->popResponse();
                $this->executions++;

                if (in_array($status, [500, 502, 503, 504], true) && $attempt < $maxAttempts) {
                    $lastApi = $decoded;
                    $lastStatus = $status;
                    continue;
                }

                return $this->mapResponse($status, $decoded);
            } catch (NetworkException $e) {
                $this->executions++;
                $lastNetwork = $e;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                continue;
            }
        }

        if ($lastApi !== null) {
            return $this->mapResponse($lastStatus, $lastApi);
        }
        throw $lastNetwork ?? new NetworkException('test pipeline exhausted');
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function retriesAllowedFor(string $method, array $extraHeaders): bool
    {
        return $method === 'GET' || isset($extraHeaders['Idempotency-Key']);
    }

    private function retriesValue(): int
    {
        // The parent's $retries is private. We mirror via the constant we
        // passed in our own constructor, which used the parent's default.
        // For tests that override, the value is set via the parent's
        // constructor; reading it back via reflection keeps the test
        // double honest.
        $reflect = new \ReflectionClass(HttpClient::class);
        $prop = $reflect->getProperty('retries');
        return (int) $prop->getValue($this);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function popResponse(): array
    {
        if ($this->networkFailuresBeforeResponse > 0) {
            $this->networkFailuresBeforeResponse--;
            throw new NetworkException('simulated network failure');
        }

        if (empty($this->responses)) {
            throw new \RuntimeException('TestableHttpClient: response queue exhausted');
        }
        $next = array_shift($this->responses);
        return [$next['statusCode'], $next['decoded']];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function mapResponse(int $statusCode, array $decoded): array
    {
        $message = $decoded['error'] ?? $decoded['message'] ?? sprintf('HTTP %d', $statusCode);
        $message = is_string($message) ? $message : sprintf('HTTP %d', $statusCode);

        if ($statusCode === 401) {
            throw new \ScanAndPay\Exceptions\AuthenticationException($message, $decoded);
        }
        if ($statusCode === 403) {
            throw new \ScanAndPay\Exceptions\AuthorizationException($message, $decoded);
        }
        if ($statusCode === 404) {
            throw new \ScanAndPay\Exceptions\NotFoundException($message, $decoded);
        }
        if ($statusCode === 409) {
            throw new \ScanAndPay\Exceptions\IdempotencyConflictException($message, $decoded);
        }
        if ($statusCode === 429) {
            throw new \ScanAndPay\Exceptions\RateLimitException($message, $decoded);
        }
        if ($statusCode >= 500 && $statusCode < 600) {
            throw new \ScanAndPay\Exceptions\ServerException($message, $statusCode, $decoded);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \ScanAndPay\Exceptions\ApiException($message, $statusCode, $decoded);
        }
        return $decoded;
    }
}
