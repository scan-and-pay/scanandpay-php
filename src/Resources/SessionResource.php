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

    /** Per-session amount ceiling — $1,000,000 in cents. */
    private const MAX_AMOUNT_CENTS = 100_000_000;

    public function __construct(
        private readonly string $merchantId,
        private readonly HttpClient $http
    ) {
    }

    /**
     * Creates a new payment session.
     *
     * `$amount` is integer cents. Floats are a TypeError under strict_types;
     * zero / negative / above-ceiling values throw ValidationException
     * before any network call.
     *
     * `$idempotencyKey` defaults to a UUIDv7 — replaying the same key inside
     * the server's 24h dedup window returns the existing session unchanged.
     */
    public function create(
        int $amount,
        string $platformOrderId,
        string $payId,
        string $merchantName,
        ?string $reference = null,
        string $source = 'api',
        string $currency = 'AUD',
        ?string $idempotencyKey = null,
    ): PaymentSession {
        if ($amount <= 0) {
            throw new ValidationException('amount must be greater than 0 (integer cents)');
        }
        if ($amount > self::MAX_AMOUNT_CENTS) {
            throw new ValidationException('amount exceeds the per-session limit ($1,000,000.00)');
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

        $key = $idempotencyKey ?? Idempotency::uuidv7();

        $body = [
            'merchantId' => $this->merchantId,
            'platformOrderId' => $platformOrderId,
            'amount' => $amount,
            'currency' => $currency,
            'payId' => $payId,
            'merchantName' => $merchantName,
            'source' => $source,
            'idempotencyKey' => $key,
        ];

        if ($reference !== null) {
            $body['reference'] = $reference;
        }

        $response = $this->http->post(
            '/createPaymentSession',
            $body,
            ['Idempotency-Key' => $key]
        );
        return PaymentSession::fromArray($response);
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
