# scanandpay/php

Official PHP SDK for [Scan & Pay](https://docs.scanandpay.com.au) —
generate PayTo PayID QR codes and payment links from any PHP backend
(Laravel, Symfony, Magento, plain PHP, …), then react to our signed
webhook when a payment is confirmed. No WordPress or framework required.

Your backend mints a session, we run the payment surface, our webhook
confirms back to you.

## How the pieces fit

```
Your backend     ──  createSession()   ─▶  Scan & Pay API     (mint QR + pay URL)
Customer phone   ──  scans QR          ─▶  pay.scanandpay.com.au   (we collect payment)
Scan & Pay       ──  signed webhook    ─▶  Your backend       (payment confirmation)
```

You never handle funds, banking credentials, or PayID resolution — the SDK
generates the link, the customer pays on our hosted surface, and you receive
a verified webhook telling you the order is paid.

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
    baseUrl: getenv('SCANANDPAY_API_BASE_URL') ?: 'https://api.scanandpay.com.au',
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

## Metadata

Attach a free-form key/value bag to any session. We echo it back unchanged
in the webhook payload + `getStatus` response, so you can correlate the
payment with your own order/customer/cart records.

```php
$session = $client->createSession(
    amount: 19.90,
    platformOrderId: 'order_456',
    payId: 'merchant@example.com.au',
    merchantName: 'Acme Coffee',
    metadata: [
        'customer_id' => 'cus_42',
        'cart_id'     => 'cart_99',
    ],
);

// Later in your webhook handler:
$event->metadata['customer_id']; // 'cus_42'
```

Limits: max **50 keys**, max **500 chars** per key + value (validated
client-side before any network call). Don't put secrets here — metadata
isn't encrypted at rest in any special way.

## API versioning

The SDK pins itself to a date-stamped API contract via the `Scanpay-Version`
header (e.g. `2026-05-07`). When we evolve the wire format, your
already-installed SDK keeps running against the contract it was built for.
Upgrade at your own pace.

```php
HttpClient::API_VERSION;  // '2026-05-07'
HttpClient::VERSION;      // '0.3.0'
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
[business.scanandpay.com.au](https://business.scanandpay.com.au), open
**Settings → Integrations**, and copy:

- `Merchant ID`
- `API Base URL`
- `API Secret`
- `Webhook Secret`

Store them in environment variables — never commit them to source control.

## Production integration checklist

Use the SDK from your backend only. Your frontend can display the returned
payment URL or QR widget, but it must never receive the API Secret or Webhook
Secret.

1. **Create your local order first.** Store the cart, customer, amount,
   currency, and a durable `platformOrderId` in your own database with a
   pending payment status.
2. **Create one Scan & Pay session for that order.** Call
   `$client->createSession()` from your backend using the same
   `platformOrderId`. Pass an idempotency key based on your order id if the
   request may be retried by a queue worker, PHP-FPM retry, or browser refresh.
3. **Render the payment step as pending.** Show the returned payment session
   to the customer with `scanandpay_checkout()`, your own QR renderer, or a
   redirect to the returned pay URL. Do not send the customer to a success
   page yet.
4. **Expose a small status endpoint.** If you use `scanandpay_checkout()`,
   point `pollUrl` at your own backend endpoint. That endpoint should fetch
   status with the SDK and return only the public status fields your UI needs.
5. **Verify the webhook on raw request bytes.** Use
   `$client->webhooks()->verify($signature, $rawBody)` before trusting any
   webhook payload. A parsed or re-serialized body will fail signature
   verification.
6. **React to our payment confirmation webhook.** Treat `$event->isPaid()`
   as the signal to mark your order paid in your own database. The Place
   Order click and QR render only mean payment has started — money has not
   moved until the webhook arrives.
7. **Keep test and live credentials separate.** Use environment variables or
   your secret manager, and never commit merchant credentials into source
   control, frontend bundles, mobile apps, logs, screenshots, or support
   tickets.

Safe to share publicly: package install commands, SDK method names,
request/response fields, webhook verification rules, idempotency guidance,
test-mode behaviour, and error handling. Keep your database schema, internal
routes, cloud project names, merchant secrets, and admin tooling private.

## Integration pitfalls

Things that bite first-time integrators (we've hit each one ourselves):

1. **Create the order BEFORE minting the session.** The session binds
   to your `platformOrderId` and the webhook references the same id
   when the payment is confirmed. Persist the order in your DB
   (status `pending`) first, then call `$client->createSession([...])`.
2. **Don't finalise the order on Place Order.** A common pattern in
   checkout code (WooCommerce included) is a hardcoded list — "if
   this gateway is stripe / paypal / etc. defer; otherwise finalise
   immediately". If that list is missing `scanandpay`, the checkout
   flashes a success screen and the QR widget never renders. Always
   treat Scan & Pay as a deferred / async-confirmation gateway, just
   like Stripe Checkout or PayPal — the customer needs to see the QR
   and complete the scan-and-pay in their banking app before the
   order can be considered paid.
3. **Webhook is the source of truth.** Mark the order paid only when
   the verified webhook arrives with `$event->isPaid()` (or
   `event.status === 'confirmed'` if you're inspecting the raw
   payload). The Place Order click on your site does NOT mean money
   has moved.

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
