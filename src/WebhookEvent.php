<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * Typed payload of an inbound webhook event.
 *
 * `amount` is a float in dollars (e.g. 19.90 for $19.90). Use it directly
 * for display — no division required.
 *
 * `status` mirrors the underlying rail outcome — note that `confirmed`
 * means the payment session is PAID (the API uses 'confirmed' on outbound
 * webhooks for backwards compatibility with older WC plugin versions).
 */
final class WebhookEvent
{
    /**
     * @param array<string, string>|null $metadata Echoed back from createSession (free-form k/v bag).
     * @param array<string, mixed>       $raw      Original decoded JSON, kept for downstream debugging.
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentSessionId,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $txId,
        public readonly int $timestamp,
        public readonly string $nonce,
        public readonly ?array $metadata,
        public readonly array $raw,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $required = ['order_id', 'payment_session_id', 'status', 'amount', 'currency', 'tx_id', 'timestamp', 'nonce'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $raw)) {
                throw new \InvalidArgumentException("Webhook payload missing required field: {$field}");
            }
        }

        $metadata = null;
        if (isset($raw['metadata']) && is_array($raw['metadata'])) {
            $metadata = [];
            foreach ($raw['metadata'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $metadata[$k] = $v;
                }
            }
        }

        return new self(
            orderId: (string) $raw['order_id'],
            paymentSessionId: (string) $raw['payment_session_id'],
            status: (string) $raw['status'],
            amount: (float) $raw['amount'],
            currency: (string) $raw['currency'],
            txId: (string) $raw['tx_id'],
            timestamp: (int) $raw['timestamp'],
            nonce: (string) $raw['nonce'],
            metadata: $metadata,
            raw: $raw,
        );
    }
}
