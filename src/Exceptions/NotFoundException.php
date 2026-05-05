<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the API returns 404 — typically a session or merchant
 * doc that doesn't exist (or never did).
 */
class NotFoundException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'Not found', array $responseBody = [], int $statusCode = 404)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
