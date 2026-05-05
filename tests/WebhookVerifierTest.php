<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use PHPUnit\Framework\TestCase;
use ScanAndPay\Exceptions\WebhookSignatureException;
use ScanAndPay\InMemoryNonceStore;
use ScanAndPay\WebhookEvent;
use ScanAndPay\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'test_webhook_secret_with_enough_entropy';
    private const PREVIOUS_SECRET = 'previous_rotation_secret_with_entropy';

    public function testValidSignaturePasses(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $event = $verifier->verify($signature, $body);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('order_456', $event->orderId);
        $this->assertSame('SP_SESS_abc123', $event->paymentSessionId);
        $this->assertSame('confirmed', $event->status);
        $this->assertTrue($event->isPaid());
        $this->assertSame(1990, $event->amount);
    }

    public function testTamperedBodyFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, self::SECRET);
        $tampered = str_replace('1990', '0001', $body);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid signature');
        $verifier->verify($signature, $tampered);
    }

    public function testWrongSecretFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, 'wrong_secret');

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify($signature, $body);
    }

    public function testEmptySignatureFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify('', $this->canonicalBody());
    }

    public function testEmptyBodyFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify(hash_hmac('sha256', '', self::SECRET), '');
    }

    public function testInvalidJsonFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = '{not-json';
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid JSON payload');
        $verifier->verify($signature, $body);
    }

    public function testMissingRequiredFieldFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = json_encode([
            'order_id' => 'order_456',
        ]);
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify($signature, $body);
    }

    public function testStaleTimestampFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = $this->bodyWithTimestamp(time() - (WebhookVerifier::TIMESTAMP_SKEW_SECONDS + 5));
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessageMatches('/Timestamp skew/');
        $verifier->verify($signature, $body);
    }

    public function testFutureTimestampFails(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = $this->bodyWithTimestamp(time() + (WebhookVerifier::TIMESTAMP_SKEW_SECONDS + 5));
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify($signature, $body);
    }

    public function testReplayedNonceFails(): void
    {
        $store = new InMemoryNonceStore();
        $verifier = new WebhookVerifier(self::SECRET, $store);
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $verifier->verify($signature, $body);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Replayed nonce');
        $verifier->verify($signature, $body);
    }

    public function testDifferentNonceSucceedsAfterFirstDelivery(): void
    {
        $store = new InMemoryNonceStore();
        $verifier = new WebhookVerifier(self::SECRET, $store);

        $body1 = $this->canonicalBody('nonce_one');
        $verifier->verify(hash_hmac('sha256', $body1, self::SECRET), $body1);

        $body2 = $this->canonicalBody('nonce_two');
        $event = $verifier->verify(hash_hmac('sha256', $body2, self::SECRET), $body2);
        $this->assertSame('nonce_two', $event->nonce);
    }

    public function testFailedStatusEvent(): void
    {
        $verifier = new WebhookVerifier(self::SECRET);
        $body = json_encode([
            'order_id' => 'order_456',
            'payment_session_id' => 'SP_SESS_abc123',
            'status' => 'failed',
            'amount' => 1990,
            'currency' => 'AUD',
            'tx_id' => 'SP_SESS_abc123',
            'timestamp' => time(),
            'nonce' => 'unique_failed',
        ]);
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $event = $verifier->verify($signature, $body);
        $this->assertTrue($event->isFailed());
        $this->assertFalse($event->isPaid());
    }

    // ---- rotation-aware verification ---------------------------------------

    public function testAcceptsSignatureFromPreviousSecretDuringRotation(): void
    {
        $verifier = new WebhookVerifier(
            webhookSecret: self::SECRET,
            previousWebhookSecret: self::PREVIOUS_SECRET,
        );
        $body = $this->canonicalBody('rotation_a');
        // Server signed with the previous secret (still in 30d grace window).
        $signature = hash_hmac('sha256', $body, self::PREVIOUS_SECRET);

        $event = $verifier->verify($signature, $body);
        $this->assertSame('rotation_a', $event->nonce);
    }

    public function testStillAcceptsCurrentSecretWithRotationConfigured(): void
    {
        $verifier = new WebhookVerifier(
            webhookSecret: self::SECRET,
            previousWebhookSecret: self::PREVIOUS_SECRET,
        );
        $body = $this->canonicalBody('rotation_b');
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $event = $verifier->verify($signature, $body);
        $this->assertSame('rotation_b', $event->nonce);
    }

    public function testRejectsSignatureFromUnrelatedSecretEvenWithPreviousConfigured(): void
    {
        $verifier = new WebhookVerifier(
            webhookSecret: self::SECRET,
            previousWebhookSecret: self::PREVIOUS_SECRET,
        );
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, 'completely_unrelated_secret');

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid signature');
        $verifier->verify($signature, $body);
    }

    public function testEmptyPreviousSecretIsTreatedAsAbsent(): void
    {
        // Passing an empty string as previousWebhookSecret should disable
        // the fallback path, not enable a wildcard.
        $verifier = new WebhookVerifier(
            webhookSecret: self::SECRET,
            previousWebhookSecret: '',
        );
        $body = $this->canonicalBody();
        $signature = hash_hmac('sha256', $body, '');

        $this->expectException(WebhookSignatureException::class);
        $verifier->verify($signature, $body);
    }

    private function canonicalBody(string $nonce = 'unique_nonce_1'): string
    {
        return $this->bodyWithTimestamp(time(), $nonce);
    }

    private function bodyWithTimestamp(int $timestamp, string $nonce = 'unique_nonce_1'): string
    {
        return json_encode([
            'order_id' => 'order_456',
            'payment_session_id' => 'SP_SESS_abc123',
            'status' => 'confirmed',
            'amount' => 1990,
            'currency' => 'AUD',
            'tx_id' => 'bank_ref_789',
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], JSON_UNESCAPED_SLASHES);
    }
}
