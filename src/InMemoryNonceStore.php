<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * Process-local nonce cache. Safe for single-instance servers (PHP-FPM
 * with a long-lived worker, CLI long-running). NOT safe across multiple
 * webserver instances behind a load balancer — supply your own NonceStore
 * backed by Redis, Memcached, or the database.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string, int> nonce => epoch-seconds-of-expiry */
    private array $seen = [];

    public function has(string $nonce): bool
    {
        $this->prune();
        return isset($this->seen[$nonce]);
    }

    public function remember(string $nonce, int $ttlSeconds): void
    {
        $this->seen[$nonce] = time() + $ttlSeconds;
    }

    private function prune(): void
    {
        $now = time();
        foreach ($this->seen as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->seen[$key]);
            }
        }
    }
}
