<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * Typed response from `createSession()` and `getStatus()`.
 *
 * `qrUrl` is a `data:image/png;base64,...` URI — render it directly in an
 * `<img src="..."`> tag, no further encoding needed.
 *
 * `amount` is a float in dollars (e.g. 19.90 for $19.90). Use it directly
 * for display — no division required.
 *
 * `status` is one of:
 *   - WAITING — created, customer hasn't paid yet
 *   - PAID    — funds confirmed (terminal)
 *   - EXPIRED — 5-minute window elapsed (terminal)
 *   - FAILED  — bank rejected or session aborted (terminal)
 */
final class PaymentSession
{
    /**
     * @param array<string, mixed> $raw  Original decoded JSON, kept for downstream debugging.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $payUrl,
        public readonly string $qrUrl,
        public readonly string $payId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $status,
        public readonly string $uiState,
        public readonly ?string $expiresAt,
        public readonly ?string $merchantName,
        public readonly ?string $createdAt,
        public readonly ?string $paidAt,
        public readonly array $raw,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    public function isExpired(): bool
    {
        return $this->status === 'EXPIRED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    public function isTerminal(): bool
    {
        return $this->status === 'PAID' || $this->status === 'EXPIRED' || $this->status === 'FAILED';
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            sessionId: (string) ($raw['sessionId'] ?? ''),
            payUrl: (string) ($raw['payUrl'] ?? ''),
            qrUrl: (string) ($raw['qrUrl'] ?? ''),
            payId: (string) ($raw['payId'] ?? ''),
            amount: (float) ($raw['amount'] ?? 0.0),
            currency: (string) ($raw['currency'] ?? 'AUD'),
            reference: (string) ($raw['reference'] ?? ''),
            status: (string) ($raw['status'] ?? ''),
            uiState: (string) ($raw['ui_state'] ?? ''),
            expiresAt: isset($raw['expiresAt']) ? (string) $raw['expiresAt'] : null,
            merchantName: isset($raw['merchantName']) ? (string) $raw['merchantName'] : null,
            createdAt: isset($raw['createdAt']) ? (string) $raw['createdAt'] : null,
            paidAt: isset($raw['paidAt']) ? (string) $raw['paidAt'] : null,
            raw: $raw,
        );
    }
}
