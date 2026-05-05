<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use PHPUnit\Framework\TestCase;
use ScanAndPay\Exceptions\ApiException;
use ScanAndPay\Exceptions\AuthenticationException;
use ScanAndPay\Exceptions\AuthorizationException;
use ScanAndPay\Exceptions\IdempotencyConflictException;
use ScanAndPay\Exceptions\NotFoundException;
use ScanAndPay\Exceptions\RateLimitException;
use ScanAndPay\Exceptions\ServerException;
use ScanAndPay\HttpClient;

/**
 * Exercises the retry + error-mapping logic without hitting curl.
 * Tests use an in-memory HttpClient subclass that overrides the single-shot
 * `executeOnce()` method via reflection-friendly hooks (see TestableHttpClient).
 */
final class HttpClientRetryTest extends TestCase
{
    public function testReturnsBodyOnFirst2xx(): void
    {
        $http = new TestableHttpClient(retries: 3);
        $http->responses = [['statusCode' => 200, 'decoded' => ['ok' => true]]];

        $result = $http->get('/ping');
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(1, $http->executions);
    }

    public function testRetriesOn503AndEventuallySucceeds(): void
    {
        $http = new TestableHttpClient(retries: 3);
        $http->responses = [
            ['statusCode' => 503, 'decoded' => ['error' => 'busy']],
            ['statusCode' => 200, 'decoded' => ['ok' => true]],
        ];

        $result = $http->get('/ping');
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $http->executions);
    }

    public function testThrowsServerExceptionAfterExhaustingRetriesOn5xx(): void
    {
        $http = new TestableHttpClient(retries: 2);
        $http->responses = array_fill(0, 5, ['statusCode' => 502, 'decoded' => ['error' => 'gateway']]);

        $this->expectException(ServerException::class);
        try {
            $http->get('/ping');
        } finally {
            $this->assertSame(3, $http->executions, 'Should attempt 1 + 2 retries = 3 total');
        }
    }

    public function testDoesNotRetryOn4xx(): void
    {
        $http = new TestableHttpClient(retries: 3);
        $http->responses = [['statusCode' => 400, 'decoded' => ['error' => 'bad']]];

        $this->expectException(ApiException::class);
        try {
            $http->get('/ping');
        } finally {
            $this->assertSame(1, $http->executions, '4xx must surface immediately');
        }
    }

    public function testDoesNotRetryOn429(): void
    {
        // Brief: never retry 4xx — strict, even for 429 (RateLimit surfaces immediately).
        $http = new TestableHttpClient(retries: 3);
        $http->responses = [['statusCode' => 429, 'decoded' => ['error' => 'slow down']]];

        $this->expectException(RateLimitException::class);
        try {
            $http->get('/ping');
        } finally {
            $this->assertSame(1, $http->executions);
        }
    }

    public function testMaps401ToAuthenticationException(): void
    {
        $http = new TestableHttpClient();
        $http->responses = [['statusCode' => 401, 'decoded' => ['error' => 'bad key']]];

        $this->expectException(AuthenticationException::class);
        $http->get('/ping');
    }

    public function testMaps403ToAuthorizationException(): void
    {
        $http = new TestableHttpClient();
        $http->responses = [['statusCode' => 403, 'decoded' => ['error' => 'forbidden']]];

        $this->expectException(AuthorizationException::class);
        $http->get('/ping');
    }

    public function testMaps404ToNotFoundException(): void
    {
        $http = new TestableHttpClient();
        $http->responses = [['statusCode' => 404, 'decoded' => ['error' => 'gone']]];

        $this->expectException(NotFoundException::class);
        $http->get('/ping');
    }

    public function testMaps409ToIdempotencyConflictException(): void
    {
        $http = new TestableHttpClient();
        $http->responses = [['statusCode' => 409, 'decoded' => ['error' => 'key in flight']]];

        $this->expectException(IdempotencyConflictException::class);
        $http->get('/ping');
    }

    public function testPostWithoutIdempotencyKeyDoesNotRetryOn5xx(): void
    {
        // Brief: never retry without idempotency key. POST with no Idempotency-Key
        // header must surface the first 5xx immediately.
        $http = new TestableHttpClient(retries: 3);
        $http->responses = array_fill(0, 5, ['statusCode' => 503, 'decoded' => ['error' => 'busy']]);

        $this->expectException(ServerException::class);
        try {
            $http->post('/createPaymentSession', ['amount' => 1990]);
        } finally {
            $this->assertSame(1, $http->executions, 'POST without Idempotency-Key must not retry');
        }
    }

    public function testPostWithIdempotencyKeyRetriesOn5xx(): void
    {
        $http = new TestableHttpClient(retries: 3);
        $http->responses = [
            ['statusCode' => 503, 'decoded' => ['error' => 'busy']],
            ['statusCode' => 200, 'decoded' => ['sessionId' => 'SP_SESS_xyz']],
        ];

        $result = $http->post(
            '/createPaymentSession',
            ['amount' => 1990],
            ['Idempotency-Key' => 'fixed-key-123']
        );
        $this->assertSame(['sessionId' => 'SP_SESS_xyz'], $result);
        $this->assertSame(2, $http->executions);
    }

    public function testGetAlwaysRetriesOnNetworkFailure(): void
    {
        $http = new TestableHttpClient(retries: 2);
        $http->networkFailuresBeforeResponse = 1;
        $http->responses = [['statusCode' => 200, 'decoded' => ['ok' => true]]];

        $result = $http->get('/ping');
        $this->assertSame(['ok' => true], $result);
        // 1 network failure + 1 successful response = 2 executions.
        $this->assertSame(2, $http->executions);
    }
}
