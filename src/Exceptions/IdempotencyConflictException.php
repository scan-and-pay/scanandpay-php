<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown on a server-side idempotency-key conflict (HTTP 409).
 * The same key was used for a different request body, or is currently
 * in flight. Retry with a fresh UUIDv7 — never reuse the same key for
 * a logically different operation.
 */
class IdempotencyConflictException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'Idempotency key conflict', array $responseBody = [], int $statusCode = 409)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
