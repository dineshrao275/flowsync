<?php

namespace Database\Seeders\Realistic;

use Carbon\CarbonInterface;

/** Small helpers over mt_rand. Seed per section with `Rng::seed($tenantIndex, 'section')` so sections stay independent. */
final class Rng
{
    public static function seed(int $tenantIndex, string $section): void
    {
        mt_srand((int) config('demo_seed.seed') + $tenantIndex * 7919 + (crc32($section) & 0xFFFF));
    }

    /** @template T @param list<T> $items @return T */
    public static function pick(array $items)
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @param array<string|int, int|float> $weights @return string|int */
    public static function weighted(array $weights)
    {
        $total = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    public static function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    /** A random moment in [$from, $to] (Carbon, inclusive) biased by `$skew` (>1 favours the start, <1 the end). */
    public static function between(CarbonInterface $from, CarbonInterface $to, float $skew = 1.0): CarbonInterface
    {
        $span = max(0, $to->getTimestamp() - $from->getTimestamp());
        $r = (mt_rand() / mt_getrandmax()) ** $skew;

        return $from->copy()->addSeconds((int) ($span * $r));
    }

    /** @template T @param list<T> $items @return list<T> */
    public static function sample(array $items, int $count): array
    {
        if ($count >= count($items)) {
            return $items;
        }
        $keys = array_rand($items, max(1, $count));

        return array_map(fn ($k) => $items[$k], (array) $keys);
    }
}
