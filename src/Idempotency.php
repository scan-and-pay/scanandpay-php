<?php

declare(strict_types=1);

namespace ScanAndPay;

/**
 * UUIDv7 — time-ordered, 128-bit, RFC 9562.
 *
 * Used as the default value for `Idempotency-Key` when callers don't supply
 * one. Time-ordering means duplicate-detection windows on the server can be
 * enforced via a TTL on the natural document id, and Firestore range queries
 * over a window of recent keys remain efficient.
 *
 *   layout (16 bytes):
 *     bytes 0-5  : 48-bit Unix milliseconds, big-endian
 *     byte  6    : version nibble (0x70) | 4 random bits
 *     byte  7    : 8 random bits
 *     byte  8    : variant bits (0b10) | 6 random bits
 *     bytes 9-15 : 56 random bits
 */
final class Idempotency
{
    public static function uuidv7(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $rand = random_bytes(10);

        // 48-bit big-endian timestamp packed into the first 6 bytes.
        $bytes = chr(($ms >> 40) & 0xff)
            . chr(($ms >> 32) & 0xff)
            . chr(($ms >> 24) & 0xff)
            . chr(($ms >> 16) & 0xff)
            . chr(($ms >> 8) & 0xff)
            . chr($ms & 0xff)
            . $rand; // 6 + 10 = 16 bytes

        // Set version 7 in byte 6 high nibble.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        // Set variant 10 in byte 8 high two bits.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
