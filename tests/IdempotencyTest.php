<?php

declare(strict_types=1);

namespace ScanAndPay\Tests;

use PHPUnit\Framework\TestCase;
use ScanAndPay\Idempotency;

final class IdempotencyTest extends TestCase
{
    public function testMatchesUuidv7Format(): void
    {
        $id = Idempotency::uuidv7();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testEncodesVersion7(): void
    {
        $id = Idempotency::uuidv7();
        $this->assertSame('7', $id[14]);
    }

    public function testEncodesVariantBitsAs10(): void
    {
        $id = Idempotency::uuidv7();
        $this->assertContains($id[19], ['8', '9', 'a', 'b']);
    }

    public function testReturns36CharacterStrings(): void
    {
        $this->assertSame(36, strlen(Idempotency::uuidv7()));
    }

    public function testIsCollisionFreeOverASmallBatch(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[Idempotency::uuidv7()] = true;
        }
        $this->assertCount(1000, $ids);
    }

    public function testTimestampPrefixIsNonDecreasing(): void
    {
        $a = Idempotency::uuidv7();
        usleep(2000); // Force a >=2ms gap so the timestamp moves on every platform.
        $b = Idempotency::uuidv7();
        $tsA = substr($a, 0, 8) . substr($a, 9, 4);
        $tsB = substr($b, 0, 8) . substr($b, 9, 4);
        $this->assertTrue(strcmp($tsA, $tsB) <= 0, "Expected $tsA <= $tsB");
    }
}
