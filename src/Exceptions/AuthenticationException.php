<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the API rejects the X-Scanpay-Key header (HTTP 401).
 * The merchant must rotate their API Secret in the dashboard.
 */
class AuthenticationException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'Invalid API key', array $responseBody = [], int $statusCode = 401)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
