<?php

declare(strict_types=1);

namespace ScanAndPay\Resources;

use ScanAndPay\Exceptions\ValidationException;
use ScanAndPay\HttpClient;
use ScanAndPay\Idempotency;
use ScanAndPay\Refund;

final class RefundResource
{
    public function __construct(
        private readonly string $merchantId,
        private readonly HttpClient $http
    ) {
    }

    public function create(
        string $paymentSessionId,
        int $amountCents,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Refund {
        if (!str_starts_with($paymentSessionId, 'SP_SESS_')) {
            throw new ValidationException('paymentSessionId must start with SP_SESS_');
        }
        if ($amountCents <= 0) {
            throw new ValidationException('amountCents must be a positive integer');
        }

        $key = $idempotencyKey ?? Idempotency::uuidv7();

        $body = [
            'merchantId' => $this->merchantId,
            'paymentSessionId' => $paymentSessionId,
            'amountCents' => $amountCents,
            'idempotencyKey' => $key,
        ];

        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        $response = $this->http->post(
            '/createRefund',
            $body,
            ['Idempotency-Key' => $key]
        );
        return Refund::fromArray($response);
    }
}
