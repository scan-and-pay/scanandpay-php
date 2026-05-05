<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the API rate-limit is exhausted (HTTP 429). The transport
 * layer will already have retried with backoff; this surface only fires
 * once retries are exhausted.
 */
class RateLimitException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'Rate limit exceeded', array $responseBody = [], int $statusCode = 429)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
