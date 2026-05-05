<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use PHPUnit\Framework\TestCase;
use ScanAndPay\Exceptions\ApiException;
use ScanAndPay\Exceptions\AuthenticationException;
use ScanAndPay\Exceptions\AuthorizationException;
use ScanAndPay\Exceptions\IdempotencyConflictException;
use ScanAndPay\Exceptions\NetworkException;
use ScanAndPay\Exceptions\NotFoundException;
use ScanAndPay\Exceptions\RateLimitException;
use ScanAndPay\Exceptions\ScanAndPayException;
use ScanAndPay\Exceptions\ServerException;
use ScanAndPay\Exceptions\ValidationException;
use ScanAndPay\Exceptions\WebhookSignatureException;

final class ExceptionsTest extends TestCase
{
    public function testAllExceptionsExtendScanAndPayException(): void
    {
        $this->assertInstanceOf(ScanAndPayException::class, new ApiException('msg', 400));
        $this->assertInstanceOf(ScanAndPayException::class, new AuthenticationException());
        $this->assertInstanceOf(ScanAndPayException::class, new AuthorizationException());
        $this->assertInstanceOf(ScanAndPayException::class, new NotFoundException());
        $this->assertInstanceOf(ScanAndPayException::class, new IdempotencyConflictException());
        $this->assertInstanceOf(ScanAndPayException::class, new RateLimitException());
        $this->assertInstanceOf(ScanAndPayException::class, new ServerException());
        $this->assertInstanceOf(ScanAndPayException::class, new NetworkException('down'));
        $this->assertInstanceOf(ScanAndPayException::class, new ValidationException('bad'));
        $this->assertInstanceOf(ScanAndPayException::class, new WebhookSignatureException('bad sig'));
    }

    public function testHttpStatusSubclassesExtendApiException(): void
    {
        $this->assertInstanceOf(ApiException::class, new AuthenticationException());
        $this->assertInstanceOf(ApiException::class, new AuthorizationException());
        $this->assertInstanceOf(ApiException::class, new NotFoundException());
        $this->assertInstanceOf(ApiException::class, new IdempotencyConflictException());
        $this->assertInstanceOf(ApiException::class, new RateLimitException());
        $this->assertInstanceOf(ApiException::class, new ServerException());
    }

    public function testNetworkAndValidationDoNotExtendApiException(): void
    {
        // Network failures and SDK-side validation aren't HTTP responses.
        $this->assertNotInstanceOf(ApiException::class, new NetworkException('down'));
        $this->assertNotInstanceOf(ApiException::class, new ValidationException('bad'));
        $this->assertNotInstanceOf(ApiException::class, new WebhookSignatureException('bad sig'));
    }

    public function testApiExceptionExposesStatusAndBody(): void
    {
        $err = new ApiException('boom', 500, ['error' => 'oops']);
        $this->assertSame(500, $err->statusCode);
        $this->assertSame(['error' => 'oops'], $err->responseBody);
    }

    public function testStatusSubclassesCarryDefaultStatusCodes(): void
    {
        $this->assertSame(401, (new AuthenticationException())->statusCode);
        $this->assertSame(403, (new AuthorizationException())->statusCode);
        $this->assertSame(404, (new NotFoundException())->statusCode);
        $this->assertSame(409, (new IdempotencyConflictException())->statusCode);
        $this->assertSame(429, (new RateLimitException())->statusCode);
        $this->assertSame(500, (new ServerException())->statusCode);
    }

    public function testSubclassesPropagateMessageOverride(): void
    {
        $this->assertSame('rotate now', (new AuthenticationException('rotate now'))->getMessage());
        $this->assertSame('blocked', (new AuthorizationException('blocked'))->getMessage());
    }
}
