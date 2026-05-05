<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * Replay-protection cache for webhook nonces.
 *
 * Implementations should keep nonces for at least 24h. Single-instance
 * deployments can use the bundled InMemoryNonceStore; multi-instance
 * deployments must use a shared store (Redis, Memcached, database).
 */
interface NonceStore
{
    /**
     * Returns true if the nonce has been seen within its TTL.
     */
    public function has(string $nonce): bool;

    /**
     * Marks the nonce as seen. The TTL hint is in seconds.
     */
    public function remember(string $nonce, int $ttlSeconds): void;
}
