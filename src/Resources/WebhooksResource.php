<?php

declare(strict_types=1);

namespace ScanAndPay\Resources;

use ScanAndPay\WebhookVerifier;
use ScanAndPay\WebhookEvent;

final class WebhooksResource
{
    private readonly WebhookVerifier $verifier;

    public function __construct(string $webhookSecret, ?string $previousWebhookSecret = null)
    {
        $this->verifier = new WebhookVerifier(
            webhookSecret: $webhookSecret,
            previousWebhookSecret: $previousWebhookSecret,
        );
    }

    /**
     * Verifies and decodes an inbound webhook event.
     *
     * @param string $signature The X-Scanpay-Signature header value.
     * @param string $body      The raw request body (php://input).
     * @return WebhookEvent
     * @throws \ScanAndPay\Exceptions\WebhookSignatureException
     */
    public function verify(string $signature, string $body): WebhookEvent
    {
        return $this->verifier->verify($signature, $body);
    }
}
