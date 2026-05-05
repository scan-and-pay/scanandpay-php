<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the API key is valid but the merchant is not authorised
 * for the requested action (HTTP 403). Typical causes: subscription
 * inactive, merchant blocked, scope missing.
 */
class AuthorizationException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'Forbidden', array $responseBody = [], int $statusCode = 403)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
