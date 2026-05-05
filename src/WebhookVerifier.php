<?php

declare(strict_types=1);

namespace ScanAndPay;

use ScanAndPay\Exceptions\WebhookSignatureException;

/**
 * Verifies inbound Scan & Pay webhook payloads.
 *
 * Always sign the **raw request body** — frameworks that auto-parse JSON
 * before your handler will break verification. In PHP, read it via
 * `file_get_contents('php://input')`.
 *
 * Rotation-aware: the constructor accepts an optional `$previousWebhookSecret`
 * to keep the previous secret valid during a 30-day rotation grace window
 * (mirrors the backend `webhookSecretRotation` shape).
 *
 * Verification order:
 *   1. Try HMAC against `current` secret. Pass → accept.
 *   2. Else try HMAC against `previous` secret if supplied. Pass → accept.
 *   3. Both fail → WebhookSignatureException.
 *
 * Tolerance: ±60 seconds on `timestamp`, in-memory replay protection on
 * `nonce` for 24 hours via the injectable NonceStore.
 */
final class WebhookVerifier
{
    public const TIMESTAMP_SKEW_SECONDS = 60;
    public const NONCE_TTL_SECONDS = 24 * 60 * 60;

    private readonly string $current;
    private readonly ?string $previous;

    public function __construct(
        string $webhookSecret,
        private readonly NonceStore $nonceStore = new InMemoryNonceStore(),
        ?string $previousWebhookSecret = null,
    ) {
        if ($webhookSecret === '') {
            throw new \InvalidArgumentException('webhookSecret must not be empty');
        }
        $this->current = $webhookSecret;
        $this->previous = $previousWebhookSecret !== null && $previousWebhookSecret !== ''
            ? $previousWebhookSecret
            : null;
    }

    /**
     * @throws WebhookSignatureException if the signature, timestamp, or nonce fails any check.
     */
    public function verify(string $signature, string $body): WebhookEvent
    {
        if ($signature === '' || $body === '') {
            throw new WebhookSignatureException('Missing signature or body');
        }

        if (!$this->matchesSecret($signature, $body, $this->current)
            && !($this->previous !== null && $this->matchesSecret($signature, $body, $this->previous))
        ) {
            throw new WebhookSignatureException('Invalid signature');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookSignatureException('Invalid JSON payload');
        }

        if (!is_array($decoded)) {
            throw new WebhookSignatureException('Webhook body is not a JSON object');
        }

        try {
            $event = WebhookEvent::fromArray($decoded);
        } catch (\InvalidArgumentException $e) {
            throw new WebhookSignatureException($e->getMessage());
        }

        $skew = abs(time() - $event->timestamp);
        if ($skew > self::TIMESTAMP_SKEW_SECONDS) {
            throw new WebhookSignatureException(sprintf('Timestamp skew %ds exceeds %ds', $skew, self::TIMESTAMP_SKEW_SECONDS));
        }

        if ($this->nonceStore->has($event->nonce)) {
            throw new WebhookSignatureException('Replayed nonce');
        }
        $this->nonceStore->remember($event->nonce, self::NONCE_TTL_SECONDS);

        return $event;
    }

    private function matchesSecret(string $signature, string $body, string $secret): bool
    {
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }
}
