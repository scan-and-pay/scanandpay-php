<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * Official Scan & Pay PHP client.
 *
 * Usage:
 *   $client = new ScanAndPay(
 *       merchantId: 'merchant_123',
 *       apiSecret: 'sp_api_live_...'
 *   );
 *
 *   // Create a session — amount is float dollars.
 *   $session = $client->sessions->create(
 *       amount: 19.90, // $19.90
 *       platformOrderId: 'order_456',
 *       payId: 'merchant@payid.com.au',
 *       merchantName: 'Acme Coffee'
 *   );
 *
 *   // Retrieve status
 *   $status = $client->sessions->retrieve($session->sessionId);
 *
 * See https://docs.scanandpay.com.au/api/payments for the full contract.
 */
final class ScanAndPay
{
    public const DEFAULT_BASE_URL = 'https://api.scanandpay.com.au';

    public readonly Resources\SessionResource $sessions;
    public readonly Resources\RefundResource $refunds;
    private readonly ?Resources\WebhooksResource $webhooks;
    private readonly HttpClient $http;

    public function __construct(
        private readonly string $merchantId,
        string $apiSecret,
        ?string $webhookSecret = null,
        ?HttpClient $http = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $previousWebhookSecret = null,
    ) {
        if ($merchantId === '') {
            throw new \InvalidArgumentException('merchantId must not be empty');
        }
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('apiSecret must not be empty');
        }

        $this->http = $http ?? new HttpClient($apiSecret, $baseUrl);
        $this->sessions = new Resources\SessionResource($this->merchantId, $this->http);
        $this->refunds = new Resources\RefundResource($this->merchantId, $this->http);
        $this->webhooks = $webhookSecret !== null
            ? new Resources\WebhooksResource($webhookSecret, $previousWebhookSecret)
            : null;
    }

    /**
     * Webhook verification resource.
     *
     * @throws \LogicException if webhookSecret was not provided to the constructor.
     */
    public function webhooks(): Resources\WebhooksResource
    {
        if ($this->webhooks === null) {
            throw new \LogicException('webhookSecret is required to use the webhooks resource');
        }
        return $this->webhooks;
    }

    /**
     * @param array<string, string>|null $metadata Free-form k/v bag echoed in
     *                                             the webhook (50 keys × 500
     *                                             chars max).
     */
    public function createSession(
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
        return $this->sessions->create(
            amountCents: $amountCents,
            platformOrderId: $platformOrderId,
            payId: $payId,
            merchantName: $merchantName,
            reference: $reference,
            source: $source,
            currency: $currency,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }

    public function getStatus(string $sessionId): PaymentSession
    {
        return $this->sessions->retrieve($sessionId);
    }

    public function createRefund(
        string $paymentSessionId,
        int $amountCents,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Refund {
        return $this->refunds->create(
            paymentSessionId: $paymentSessionId,
            amountCents: $amountCents,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Health check — returns true if the API is reachable.
     */
    public function ping(): bool
    {
        try {
            $response = $this->http->get('/ping');
            return ($response['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }
}
