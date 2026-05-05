# Changelog

All notable changes to `scanandpay/php` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this package adheres to [SemVer](https://semver.org/spec/v2.0.0.html)
once it reaches `1.0.0`. Pre-1.0 minor versions may include breaking changes.

## [0.2.0] — 2026-05-04

### Breaking

- **`amount` is now `int` (cents).** The `float $amount` typehint on
  `ScanAndPay::createSession()` and `SessionResource::create()` is now
  `int $amount`. Under `declare(strict_types=1)` (which the SDK uses),
  passing a float now throws `\TypeError`. Migrate by multiplying and
  rounding: `(int) round(19.90 * 100) === 1990`.
- **`PaymentSession::$amount` and `WebhookEvent::$amount` are now `int`.**
  Display-side formatting must divide by 100.
- **Licence changed from MIT to Apache-2.0** to align across all SDKs.
- **HTTP transport never retries 4xx responses.** Previous version had
  no retry layer at all; the new layer adds retries for 5xx + network
  failures only. 408/429 surface immediately as typed exceptions.
- **POST requests retry only when an `Idempotency-Key` header is
  present.** Mutating retries without idempotency are unsafe by default.
  The SDK auto-generates a UUIDv7 key for `createSession`, so this is
  transparent for the standard call path.

### Added

- `Idempotency::uuidv7()` static helper for generating time-ordered
  idempotency keys (RFC 9562).
- `Idempotency-Key` header sent on every `createSession` call. UUIDv7
  by default; pass `idempotencyKey:` to override.
- HTTP transport with retry: exponential backoff with full-half jitter
  (250 → 500 → 1000ms baseline, ±50 % jitter), 3 retries by default.
- Full exception hierarchy mirroring the Node SDK (all extend
  `Exceptions\ScanAndPayException`):
  - `Exceptions\ApiException` — base for non-2xx responses, carries
    `statusCode` + `responseBody`.
  - `Exceptions\AuthenticationException` (HTTP 401)
  - `Exceptions\AuthorizationException` (HTTP 403)
  - `Exceptions\NotFoundException` (HTTP 404)
  - `Exceptions\IdempotencyConflictException` (HTTP 409)
  - `Exceptions\RateLimitException` (HTTP 429)
  - `Exceptions\ServerException` (HTTP 5xx after retry exhaustion)
  - `Exceptions\NetworkException` — transport failure post-retry.
  - `Exceptions\ValidationException` — SDK-side input rejection.
- `WebhookVerifier` is now **rotation-aware**. The constructor accepts an
  optional `$previousWebhookSecret` argument; verification tries `current`
  first then falls back to `previous` for the duration of a rotation
  grace window. Passes through `Resources\WebhooksResource` and
  `ScanAndPay::__construct()` as the `previousWebhookSecret:` named arg.
- Per-session amount ceiling: $1,000,000.00 (100,000,000 cents).
- `source: 'shop-app'` accepted as a first-party platform identifier.
- `LICENSE` (Apache-2.0) at the package root.
- New tests: `IdempotencyTest`, `ExceptionsTest`, `HttpClientRetryTest`.
  `ScanAndPayTest` extended for cents enforcement, idempotency-key
  auto-generation, and Idempotency-Key header capture.
  `WebhookVerifierTest` extended for rotation-aware verification.

### Changed

- `HttpClient::post()` and `HttpClient::get()` now accept an
  `array $extraHeaders` parameter so callers (notably SessionResource)
  can pass `Idempotency-Key`. Existing callers without this argument
  keep working.
- `HttpClient::VERSION` bumped to `'0.2.0'`.
- `Render\checkout` template now divides `$session->amount` by 100 for
  display (since it's now cents).

### Migration notes

```diff
- $client->createSession(amount: 19.90, ...);
+ $client->createSession(amount: 1990, ...);

- } catch (\InvalidArgumentException $e) { ... }
  // Still works — ValidationException IS catchable as
  // ScanAndPayException, but the legacy message-based catches need
  // upgrading to instanceof checks.
+ } catch (\ScanAndPay\Exceptions\ValidationException $e) { ... }

- new WebhookVerifier($current);
+ new WebhookVerifier(
+     webhookSecret: $current,
+     previousWebhookSecret: $previous, // optional, for 30d grace
+ );
```

## [0.1.0] — 2026-05-04

Initial release. Client init, `sessions->create`, `sessions->retrieve`,
`webhooks()->verify`, render helper, WPNonceStore for WordPress.
