<?php

declare(strict_types=1);

namespace ScanAndPay;

final class Refund
{
    public function __construct(
        public readonly string $refundId,
        public readonly string $status,
        public readonly int $amountCents,
        public readonly string $originalInitiationId,
        public readonly string $paymentSessionId,
        public readonly string $idempotencyKey,
        public readonly bool $idempotent,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            refundId: (string) ($raw['refundId'] ?? ''),
            status: (string) ($raw['status'] ?? ''),
            amountCents: (int) ($raw['amountCents'] ?? 0),
            originalInitiationId: (string) ($raw['originalInitiationId'] ?? ''),
            paymentSessionId: (string) ($raw['paymentSessionId'] ?? ''),
            idempotencyKey: (string) ($raw['idempotencyKey'] ?? ''),
            idempotent: (bool) ($raw['idempotent'] ?? false),
            raw: $raw,
        );
    }
}
