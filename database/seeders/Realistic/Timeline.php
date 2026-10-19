<?php

namespace Database\Seeders\Realistic;

use Carbon\CarbonImmutable;

/**
 * When things happened for one tenant. Every dated record in the tenant stays inside [created, end]:
 * a trial tenant is days old, a suspended or cancelled one has no activity after it stopped.
 */
final class Timeline
{
    public function __construct(
        public readonly CarbonImmutable $now,
        public readonly CarbonImmutable $created,
        public readonly CarbonImmutable $end,
        public readonly ?CarbonImmutable $trialEndsAt,
        public readonly ?CarbonImmutable $canceledAt,
        public readonly ?CarbonImmutable $pastDueAt,
        public readonly ?CarbonImmutable $expiredAt,
        public readonly int $trialDays,
    ) {}

    /** @param array<string, mixed> $bp */
    public static function for(array $bp, CarbonImmutable $now): self
    {
        Rng::seed($bp['index'], 'timeline');
        $day = fn (int $lo, int $hi): int => mt_rand($lo, $hi);
        $trialDays = 14;

        return match ($bp['state']) {
            'trialing' => (function () use ($now, $day, $trialDays) {
                $remaining = $day(3, 11);

                return new self($now, $now->subDays($trialDays - $remaining)->setTime(9, 0), $now, $now->addDays($remaining), null, null, null, $trialDays);
            })(),
            'active' => new self($now, $now->subDays($day(90, 900))->setTime(9, 0), $now, null, null, null, null, $trialDays),
            'expired' => (function () use ($now, $day, $trialDays) {
                $expired = $now->subDays($day(5, 60));

                return new self($now, $now->subDays($day(240, 700))->setTime(9, 0), $expired, null, null, null, $expired, $trialDays);
            })(),
            'past_due' => (function () use ($now, $day, $trialDays) {
                $since = $now->subDays($day(3, 20));

                return new self($now, $now->subDays($day(120, 600))->setTime(9, 0), $since, null, null, $since, null, $trialDays);
            })(),
            default => (function () use ($now, $day, $trialDays) { // canceled
                $at = $now->subDays($day(10, 90));

                return new self($now, $now->subDays($day(150, 650))->setTime(9, 0), $at, null, $at, null, null, $trialDays);
            })(),
        };
    }

    public function days(): int
    {
        return max(1, (int) $this->created->diffInDays($this->end));
    }
}
