<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Base class for any non-2xx response from the API. Carries the HTTP status
 * code and the decoded response body so callers can branch on either.
 *
 * Specific status codes are surfaced via subclasses:
 *   401 → {@see AuthenticationException}
 *   403 → {@see AuthorizationException}
 *   404 → {@see NotFoundException}
 *   409 → {@see IdempotencyConflictException}
 *   429 → {@see RateLimitException}
 *   5xx → {@see ServerException}
 *
 * Other 4xx codes (400, 405, 422, …) surface as the bare ApiException.
 */
class ApiException extends ScanAndPayException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
