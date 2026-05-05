<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the API returns a 5xx after the transport retry budget is
 * exhausted. Indicates a sustained upstream incident — surface to the
 * caller and surface to your error-tracking pipeline.
 */
class ServerException extends ApiException
{
    /** @param array<string, mixed> $responseBody */
    public function __construct(string $message = 'API server error', int $statusCode = 500, array $responseBody = [])
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
