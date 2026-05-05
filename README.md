# scanandpay/php

Official PHP SDK for [Scan & Pay](https://docs.scanandpay.com.au) — accept
PayTo PayID payments via QR code from any PHP backend (Laravel, Symfony,
Magento, plain PHP, …). No WordPress or framework required.

## Install

```bash
composer require scanandpay/php
```

Requires PHP 8.1+ with the `curl`, `json`, and `hash` extensions.

## Quickstart

```php
use ScanAndPay\ScanAndPay;
use ScanAndPay\Exceptions\WebhookSignatureException;

$client = new ScanAndPay(
    merchantId: getenv('SCANANDPAY_MERCHANT_ID'),
    apiSecret: getenv('SCANANDPAY_API_SECRET'),
    webhookSecret: getenv('SCANANDPAY_WEBHOOK_SECRET'), // optional
);

// 1. Create a session at checkout. Amount is float dollars.
$session = $client->createSession(
    amount: 19.90,  // $19.90
    platformOrderId: 'order_456',
    payId: 'merchant@example.com.au',
    merchantName: 'Acme Coffee',
);

// 2. Render the QR widget on the page.
echo scanandpay_checkout($session, pollUrl: '/scanandpay/status');

// 3. In your webhook handler, verify and consume the event.
try {
    $event = $client->webhooks()->verify(
        signature: $_SERVER['HTTP_X_SCANPAY_SIGNATURE'] ?? '',
        body: file_get_contents('php://input'),
    );

    if ($event->isPaid()) {
        // Mark order paid using $event->orderId, $event->txId, ...
    }
} catch (WebhookSignatureException $e) {
    http_response_code(401);
    exit('Invalid webhook');
}
```

## Amount format

`amount` is always **float dollars** (e.g. `19.90` for $19.90). This matches
the Scan & Pay API directly — no multiplication or division needed.

```php
$client->createSession(amount: 19.90, ...);   // ✓ $19.90
$client->createSession(amount: 0.50, ...);    // ✓ $0.50
$client->createSession(amount: 1000.00, ...); // ✓ $1,000.00
$client->createSession(amount: -1, ...);      // ✗ ValidationException
$client->createSession(amount: 0, ...);       // ✗ ValidationException
```

For display, use `$session->amount` directly:

```php
$display = number_format($session->amount, 2);  // "19.90"
```

## Idempotency

Every `createSession` call sends an `Idempotency-Key` header. The SDK
generates a UUIDv7 by default; pass your own to make retries safe across
process restarts.

```php
$client->createSession(
    amount: 19.90,
    platformOrderId: 'order_456',
    payId: 'merchant@example.com.au',
    merchantName: 'Acme Coffee',
    idempotencyKey: 'order_456:attempt_1',
);
```

## Retries

Transient failures (5xx, network) are retried 3× with exponential
backoff and full-half jitter (≈250 → 500 → 1000ms). 4xx responses
surface immediately. POSTs retry **only when an Idempotency-Key header
is present** — the SDK auto-sends one for `createSession` so this is
transparent for the standard path.

Tune by injecting a configured `HttpClient`:

```php
use ScanAndPay\HttpClient;

$http = new HttpClient(
    apiSecret: getenv('SCANANDPAY_API_SECRET'),
    baseUrl: 'https://api.scanandpay.com.au',
    timeoutSeconds: 30,
    retries: 5,
    baseMs: 100,
);
$client = new ScanAndPay(
    merchantId: 'm', apiSecret: 's', http: $http,
);
```

## Webhook secret rotation

Rotate the merchant webhook secret in the dashboard; the SDK accepts
both the new and old values during the 30-day grace window:

```php
$client = new ScanAndPay(
    merchantId: 'm',
    apiSecret: 's',
    webhookSecret: $current,           // signs new outbound deliveries
    previousWebhookSecret: $previous,  // accepted on inbound until grace expires
);
```

`WebhookVerifier::verify()` tries `current` first, falls back to
`previous`, then throws `WebhookSignatureException`.

## Errors

All thrown errors extend `ScanAndPay\Exceptions\ScanAndPayException`.
HTTP-status-mapped subclasses extend `ApiException`:

| Class | HTTP | Surfaces when |
|---|---|---|
| `ValidationException` | — | Bad input rejected before any HTTP call |
| `AuthenticationException` | 401 | API rejected `X-Scanpay-Key` |
| `AuthorizationException` | 403 | Key valid, action not permitted |
| `NotFoundException` | 404 | Session/merchant doesn't exist |
| `IdempotencyConflictException` | 409 | Key in flight or reused with different body |
| `RateLimitException` | 429 | Exhausted rate limit (no retry on 4xx) |
| `ApiException` | other 4xx | 400, 405, 422, … (no retry) |
| `ServerException` | 5xx | After retries exhausted |
| `NetworkException` | — | Transport failure after retries exhausted |
| `WebhookSignatureException` | — | Webhook signature/timestamp/replay check failed |

## Credentials

Sign in to the merchant dashboard at
[merchant.scanandpay.com.au](https://merchant.scanandpay.com.au), open
**Settings → Integrations**, and copy:

- `Merchant ID`
- `API Secret`
- `Webhook Secret`

Store them in environment variables — never commit them to source control.

## Documentation

- **API reference:** https://docs.scanandpay.com.au/api/payments
- **Webhook payload + signing:** https://docs.scanandpay.com.au/api/webhooks
- **OpenAPI spec:** https://docs.scanandpay.com.au/api-spec.yaml

## Local development

```bash
composer install
composer test          # PHPUnit
composer lint          # php-cs-fixer dry-run
composer format        # php-cs-fixer apply
```

## Versioning

SemVer. Pre-1.0 — expect minor breaking changes between versions until 1.0.

## Licence

Apache-2.0.
