<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** P8.1 - RFC 6238 appendix B vectors (SHA-1) and the base32 round trip. */
class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    public function test_it_matches_the_rfc_6238_sha1_vectors(): void
    {
        $secret = Totp::base32Encode(self::RFC_SECRET);

        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037'] as $time => $expected) {
            $this->assertSame($expected, Totp::code($secret, $time, 8), "t={$time}");
        }
    }

    public function test_base32_round_trips_and_ignores_padding_and_case(): void
    {
        $bytes = random_bytes(20);
        $encoded = Totp::base32Encode($bytes);

        $this->assertSame($bytes, Totp::base32Decode($encoded));
        $this->assertSame($bytes, Totp::base32Decode(strtolower($encoded).'===='));
    }

    public function test_verify_accepts_one_step_of_drift_and_returns_the_step(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertSame(intdiv($now, 30), Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertSame(intdiv($now, 30) - 1, Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
    }

    public function test_verify_rejects_malformed_input(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', 'abcdef', '12345', '1234567', '12 34'] as $bad) {
            $this->assertNull(Totp::verify($secret, $bad));
        }
    }

    public function test_uri_carries_issuer_and_secret(): void
    {
        $uri = Totp::uri('ABC234', 'a@b.test', 'Flow Sync');

        $this->assertStringStartsWith('otpauth://totp/Flow%20Sync%3Aa%40b.test?secret=ABC234', $uri);
        $this->assertStringContainsString('issuer=Flow%20Sync', $uri);
    }
}
