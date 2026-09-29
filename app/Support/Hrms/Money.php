<?php

namespace App\Support\Hrms;

use InvalidArgumentException;

/**
 * Money in integer minor units (paise, cents) with bcmath-safe arithmetic.
 *
 * The binding rule (D2.16.6, 0.6): no float ever touches money. A float
 * cannot represent 1.67 exactly, and a payroll that adds thousands of
 * inexact paise drifts by whole rupees — silently, because nothing throws.
 * Every amount here is an int; every operation stays in strings until the
 * final minor-unit int lands.
 *
 * Rounding is half-up at every boundary (Indian payroll convention): the
 * fractional paisa goes to the employee, never the ledger.
 */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency = 'INR',
    ) {}

    /**
     * From a decimal string (`'1234.56'`) to minor units, half-up.
     * Accepts ints (whole majors) for literals; floats are refused — a
     * float argument is the exact bug this class exists to prevent, so the
     * signature takes it only to reject it with a message instead of a
     * TypeError.
     *
     * @throws InvalidArgumentException on a float or an unparseable string
     */
    public static function fromDecimal(string|int|float $amount, string $currency = 'INR'): self
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException('Money never takes floats; pass the decimal as a string.');
        }

        $text = trim((string) $amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("Not a decimal amount: {$text}.");
        }

        return new self(self::toMinorUnits($text), $currency);
    }

    public static function zero(string $currency = 'INR'): self
    {
        return new self(0, $currency);
    }

    /**
     * Split minor units across parts without losing a paisa: the first
     * `$remainder` parts carry one extra unit (`allocate(1430, 3)` →
     * 477, 477, 476 — never 476.67 rounded three ways).
     *
     * @return list<Money>
     */
    public static function allocate(int $minor, int $parts, string $currency = 'INR'): array
    {
        if ($parts <= 0) {
            throw new InvalidArgumentException('Allocate across at least one part.');
        }

        $each = intdiv($minor, $parts);
        $remainder = $minor % $parts;

        $out = [];

        for ($i = 0; $i < $parts; $i++) {
            $out[] = new self($each + ($i < $remainder ? 1 : 0), $currency);
        }

        return $out;
    }

    /**
     * Back to majors: `'1234.56'`, always two places.
     */
    public function toDecimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return $sign.sprintf('%d.%02d', intdiv($abs, 100), $abs % 100);
    }

    public function add(Money $other): Money
    {
        $this->requireSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function sub(Money $other): Money
    {
        $this->requireSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Scale by a decimal factor string (`'1.5'`, `'0.12'`), half-up.
     */
    public function multiply(string $factor): Money
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', trim($factor))) {
            throw new InvalidArgumentException("Not a decimal factor: {$factor}.");
        }

        return new self(self::toMinorUnits(bcmul($this->toDecimal(), trim($factor), 4)), $this->currency);
    }

    /**
     * A percentage of this amount (`percent('12')` of 50000.00 → 6000.00),
     * half-up.
     */
    public function percent(string $percent): Money
    {
        return $this->multiply(bcdiv(trim($percent), '100', 6));
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /**
     * Decimal majors to minor units, half-up, in strings throughout:
     * `bcmul(..., 2)` keeps two guard places, then ±0.5 truncated to an
     * int rounds half away from zero — toward the employee on positives.
     */
    private static function toMinorUnits(string $major): int
    {
        $scaled = bcmul($major, '100', 2);

        return (int) (str_starts_with(ltrim($scaled), '-') ? bcsub($scaled, '0.5', 0) : bcadd($scaled, '0.5', 0));
    }

    private function requireSameCurrency(Money $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
