<?php

namespace Tests\Unit;

use App\Support\PlatformHealthSummary;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for PlatformHealthSummary::ping().
 * Pure unit — no application boot, no database.
 */
class PlatformHealthSummaryTest extends TestCase
{
    public function test_ping_returns_healthy_status(): void
    {
        $result = PlatformHealthSummary::ping();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertCount(2, $result);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_ping_timestamp_is_valid_iso8601(): void
    {
        $result = PlatformHealthSummary::ping();
        $ts = $result['timestamp'];

        $this->assertIsString($ts);

        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $ts);
        $this->assertNotFalse($parsed, 'timestamp must parse with DATE_ATOM');

        $roundTrip = $parsed->format(\DATE_ATOM);
        $this->assertEquals($ts, $roundTrip, 'timestamp must round-trip through DATE_ATOM');
    }

    public function test_ping_timestamp_is_recent(): void
    {
        $result = PlatformHealthSummary::ping();
        $ts = $result['timestamp'];

        $this->assertIsString($ts);

        $now = new \DateTimeImmutable;
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $ts);
        $this->assertNotFalse($parsed);

        $diff = $now->getTimestamp() - $parsed->getTimestamp();
        $this->assertGreaterThanOrEqual(-2, $diff, 'timestamp should be within 2 seconds of now');
        $this->assertLessThanOrEqual(2, $diff, 'timestamp should be within 2 seconds of now');
    }
}
