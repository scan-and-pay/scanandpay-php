<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use PHPUnit\Framework\TestCase;
use ScanAndPay\Exceptions\ValidationException;
use ScanAndPay\HttpClient;
use ScanAndPay\PaymentSession;
use ScanAndPay\ScanAndPay;

final class ScanAndPayTest extends TestCase
{
    public function testEmptyMerchantIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScanAndPay(merchantId: '', apiSecret: 'sp_api_test');
    }

    public function testEmptyApiSecretRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/apiSecret/');
        new ScanAndPay(merchantId: 'merchant_test', apiSecret: '');
    }

    public function testCreateSessionRejectsNegativeAmount(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->createSession(
            amount: -1.0,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
        );
    }

    public function testCreateSessionRejectsZeroAmount(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->createSession(
            amount: 0.0,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
        );
    }

    public function testCreateSessionRejectsAmountAboveCeiling(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/per-session limit/');
        $client->createSession(
            amount: 1_000_000.01,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
        );
    }

    public function testCreateSessionAcceptsFloatAmount(): void
    {
        $http = new FakeHttpClient();
        $http->postResponse = $this->canonicalSessionResponse();
        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $session = $client->createSession(
            amount: 19.90,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
        );
        $this->assertInstanceOf(PaymentSession::class, $session);
    }

    public function testCreateSessionRejectsNonAudCurrency(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->createSession(
            amount: 10.00,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
            currency: 'USD',
        );
    }

    public function testCreateSessionRejectsUnknownSource(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->createSession(
            amount: 10.00,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
            source: 'tiktok',
        );
    }

    public function testCreateSessionRejectsEmptyOrderId(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->createSession(
            amount: 10.00,
            platformOrderId: '',
            payId: 'm@x.com',
            merchantName: 'X',
        );
    }

    public function testCreateSessionAcceptsShopAppSource(): void
    {
        $http = new FakeHttpClient();
        $http->postResponse = $this->canonicalSessionResponse();
        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $session = $client->createSession(
            amount: 99.50,
            platformOrderId: 'order_1',
            payId: 'm@x.com',
            merchantName: 'X',
            source: 'shop-app',
        );
        $this->assertInstanceOf(PaymentSession::class, $session);
    }

    public function testCreateSessionPostsExpectedBody(): void
    {
        $http = new FakeHttpClient();
        $http->postResponse = $this->canonicalSessionResponse();

        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $session = $client->createSession(
            amount: 99.50,
            platformOrderId: 'order_456',
            payId: 'merchant@example.com.au',
            merchantName: 'Acme Coffee',
            reference: 'Order #456',
            source: 'api',
            idempotencyKey: 'caller_supplied_key',
        );

        $this->assertSame('/createPaymentSession', $http->lastPath);
        $this->assertSame([
            'merchantId' => 'merchant_test',
            'platformOrderId' => 'order_456',
            'amount' => 99.50,
            'currency' => 'AUD',
            'payId' => 'merchant@example.com.au',
            'merchantName' => 'Acme Coffee',
            'source' => 'api',
            'idempotencyKey' => 'caller_supplied_key',
            'reference' => 'Order #456',
        ], $http->lastBody);

        $this->assertSame(['Idempotency-Key' => 'caller_supplied_key'], $http->lastExtraHeaders);

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertSame('SP_SESS_abc123', $session->sessionId);
        $this->assertSame('WAITING', $session->status);
        $this->assertSame(99.50, $session->amount);
    }

    public function testCreateSessionAutoGeneratesIdempotencyKey(): void
    {
        $http = new FakeHttpClient();
        $http->postResponse = $this->canonicalSessionResponse();

        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $client->createSession(
            amount: 99.50,
            platformOrderId: 'order_456',
            payId: 'merchant@example.com.au',
            merchantName: 'Acme Coffee',
        );

        $this->assertArrayHasKey('Idempotency-Key', $http->lastExtraHeaders);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $http->lastExtraHeaders['Idempotency-Key']
        );
    }

    public function testGetStatusRejectsBadSessionPrefix(): void
    {
        $client = $this->client();
        $this->expectException(ValidationException::class);
        $client->getStatus('not_a_session');
    }

    public function testGetStatusUsesSessionIdQueryParam(): void
    {
        $http = new FakeHttpClient();
        $http->getResponse = $this->canonicalSessionResponse();
        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $client->getStatus('SP_SESS_abc123');

        $this->assertSame('/getPaymentStatus', $http->lastPath);
        $this->assertSame(['sessionId' => 'SP_SESS_abc123'], $http->lastQuery);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $http = new FakeHttpClient();
        $http->getResponse = ['success' => true, 'message' => 'pong'];
        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $this->assertTrue($client->ping());
    }

    public function testPingReturnsFalseOnException(): void
    {
        $http = new FakeHttpClient();
        $http->throwOnGet = new \RuntimeException('network down');
        $client = new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: $http,
        );

        $this->assertFalse($client->ping());
    }

    private function client(): ScanAndPay
    {
        return new ScanAndPay(
            merchantId: 'merchant_test',
            apiSecret: 'sp_api_test',
            http: new FakeHttpClient(),
        );
    }

    /** @return array<string, mixed> */
    private function canonicalSessionResponse(): array
    {
        return [
            'success' => true,
            'sessionId' => 'SP_SESS_abc123',
            'payUrl' => 'https://pay.scanandpay.com.au/p/SP_SESS_abc123',
            'qrUrl' => 'data:image/png;base64,AAAA',
            'payId' => 'merchant@example.com.au',
            'amount' => 99.50,
            'currency' => 'AUD',
            'reference' => 'Order #456',
            'status' => 'WAITING',
            'ui_state' => 'AMBER',
            'expiresAt' => '2026-04-30T12:35:00.000Z',
        ];
    }
}

/**
 * Test double for HttpClient. Public properties capture call args; configure
 * `getResponse` / `postResponse` for fixtures, or set `throwOnGet` / `throwOnPost`
 * to simulate transport errors.
 */
final class FakeHttpClient extends HttpClient
{
    /** @var array<string, mixed> */
    public array $getResponse = [];

    /** @var array<string, mixed> */
    public array $postResponse = [];

    public ?\Throwable $throwOnGet = null;

    public ?\Throwable $throwOnPost = null;

    public ?string $lastPath = null;

    /** @var array<string, mixed>|null */
    public ?array $lastBody = null;

    /** @var array<string, scalar>|null */
    public ?array $lastQuery = null;

    /** @var array<string, string> */
    public array $lastExtraHeaders = [];

    public function __construct()
    {
        // Skip parent constructor — we don't need a real apiSecret/baseUrl.
    }

    public function post(string $path, array $body, array $extraHeaders = []): array
    {
        $this->lastPath = $path;
        $this->lastBody = $body;
        $this->lastExtraHeaders = $extraHeaders;
        if ($this->throwOnPost !== null) {
            throw $this->throwOnPost;
        }
        return $this->postResponse;
    }

    public function get(string $path, array $query = [], array $extraHeaders = []): array
    {
        $this->lastPath = $path;
        $this->lastQuery = $query;
        $this->lastExtraHeaders = $extraHeaders;
        if ($this->throwOnGet !== null) {
            throw $this->throwOnGet;
        }
        return $this->getResponse;
    }
}
