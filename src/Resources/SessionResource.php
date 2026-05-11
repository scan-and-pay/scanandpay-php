<?php

declare(strict_types=1);

namespace ScanAndPay\Resources;

use ScanAndPay\Exceptions\ValidationException;
use ScanAndPay\HttpClient;
use ScanAndPay\Idempotency;
use ScanAndPay\PaymentSession;

final class SessionResource
{
    /** @var string[] */
    private const VALID_SOURCES = ['api', 'woocommerce', 'shopify', 'pos', 'magento', 'wordpress', 'shop-app'];

    /** Per-session amount ceiling — $1,000,000 = 100,000,000 cents. */
    private const MAX_AMOUNT_CENTS = 100_000_000;

    /** Metadata cap — max 50 keys, each key + value ≤ 500 chars. */
    public const METADATA_MAX_KEYS = 50;
    public const METADATA_MAX_CHARS = 500;

    public function __construct(
        private readonly string $merchantId,
        private readonly HttpClient $http
    ) {
    }

    /**
     * Creates a new payment session.
     *
     * `$amountCents` is a positive integer in cents (e.g. 1990 for $19.90).
     *
     * @param array<string, string>|null $metadata
     */
    public function create(
        int $amountCents,
        string $platformOrderId,
        string $payId,
        string $merchantName,
        ?string $reference = null,
        string $source = 'api',
        string $currency = 'AUD',
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): PaymentSession {
        if ($amountCents <= 0) {
            throw new ValidationException('amountCents must be a positive integer (cents, e.g. 1990 for $19.90)');
        }
        if ($amountCents > self::MAX_AMOUNT_CENTS) {
            throw new ValidationException('amountCents exceeds the per-session limit (100,000,000 = $1,000,000)');
        }
        if ($currency !== 'AUD') {
            throw new ValidationException('Only AUD is supported');
        }
        if ($platformOrderId === '') {
            throw new ValidationException('platformOrderId must not be empty');
        }
        if ($merchantName === '') {
            throw new ValidationException('merchantName must not be empty');
        }
        if ($payId === '') {
            throw new ValidationException('payId must not be empty');
        }
        if (!in_array($source, self::VALID_SOURCES, true)) {
            throw new ValidationException('Invalid source: ' . $source);
        }
        if ($metadata !== null) {
            self::assertMetadata($metadata);
        }

        $key = $idempotencyKey ?? Idempotency::uuidv7();

        $body = [
            'merchantId' => $this->merchantId,
            'platformOrderId' => $platformOrderId,
            'amountCents' => $amountCents,
            'currency' => $currency,
            'payId' => $payId,
            'merchantName' => $merchantName,
            'source' => $source,
            'idempotencyKey' => $key,
        ];

        if ($reference !== null) {
            $body['reference'] = $reference;
        }
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }

        $response = $this->http->post(
            '/createPaymentSession',
            $body,
            ['Idempotency-Key' => $key]
        );
        return PaymentSession::fromArray($response);
    }

    /**
     * @param array<string, string> $metadata
     */
    private static function assertMetadata(array $metadata): void
    {
        if (count($metadata) > self::METADATA_MAX_KEYS) {
            throw new ValidationException(
                'metadata may not exceed ' . self::METADATA_MAX_KEYS . ' keys (got ' . count($metadata) . ')'
            );
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new ValidationException('metadata keys must be non-empty strings');
            }
            if (!is_string($value)) {
                throw new ValidationException('metadata value for "' . $key . '" must be a string');
            }
            if (strlen($key) > self::METADATA_MAX_CHARS) {
                throw new ValidationException('metadata key exceeds ' . self::METADATA_MAX_CHARS . ' chars');
            }
            if (strlen($value) > self::METADATA_MAX_CHARS) {
                throw new ValidationException(
                    'metadata value for "' . $key . '" exceeds ' . self::METADATA_MAX_CHARS . ' chars'
                );
            }
        }
    }

    /**
     * Retrieves a payment session status.
     */
    public function retrieve(string $sessionId): PaymentSession
    {
        if (!str_starts_with($sessionId, 'SP_SESS_')) {
            throw new ValidationException('sessionId must start with SP_SESS_');
        }

        $response = $this->http->get('/getPaymentStatus', ['sessionId' => $sessionId]);
        return PaymentSession::fromArray($response);
    }
}
