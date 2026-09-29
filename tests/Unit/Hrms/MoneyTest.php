<?php

namespace Tests\Unit\Hrms;

use App\Support\Hrms\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Money arithmetic is exact or it is a payroll bug: every assertion here
 * pins minor-unit results, including the cases where floats famously lie
 * (`0.1 + 0.2`, `1.005` rounding, three-way splits of odd paise).
 */
class MoneyTest extends TestCase
{
    public function test_from_decimal_parses_half_up_without_floats(): void
    {
        $this->assertSame(123456, Money::fromDecimal('1234.56')->minor);
        $this->assertSame(101, Money::fromDecimal('1.005')->minor);
        $this->assertSame(-101, Money::fromDecimal('-1.005')->minor);
        $this->assertSame(500000, Money::fromDecimal(5000)->minor);
        $this->assertSame('1234.56', Money::fromDecimal('1234.56')->toDecimal());
        $this->assertSame('0.05', Money::fromDecimal('0.05')->toDecimal());
    }

    public function test_floats_are_refused_with_a_message_not_a_type_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal(1.1);
    }

    public function test_add_and_subtract_stay_exact(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in floats; here it is 30 paise.
        $sum = Money::fromDecimal('0.1')->add(Money::fromDecimal('0.2'));

        $this->assertSame(30, $sum->minor);
        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertSame(700, Money::fromDecimal('10.00')->sub(Money::fromDecimal('3.00'))->minor);
    }

    public function test_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('10', 'INR')->add(Money::fromDecimal('10', 'USD'));
    }

    public function test_multiply_and_percent_round_half_up(): void
    {
        // 12% of 50000.00, and a factor with its own fraction.
        $this->assertSame(600000, Money::fromDecimal('50000')->percent('12')->minor);
        $this->assertSame(16670, Money::fromDecimal('100')->multiply('1.667')->minor);
        $this->assertSame(167, Money::fromDecimal('1.00')->multiply('1.667')->minor);
    }

    public function test_allocate_loses_no_paisa(): void
    {
        $parts = Money::allocate(1430, 3);

        $this->assertSame([477, 477, 476], array_map(fn (Money $part): int => $part->minor, $parts));
        $this->assertSame(1430, array_sum(array_map(fn (Money $part): int => $part->minor, $parts)));

        $single = Money::allocate(100, 1);

        $this->assertSame([100], array_map(fn (Money $part): int => $part->minor, $single));
    }

    public function test_allocate_needs_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::allocate(100, 0);
    }
}
