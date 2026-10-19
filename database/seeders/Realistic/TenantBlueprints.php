<?php

namespace Database\Seeders\Realistic;

use Illuminate\Support\Str;

/**
 * The 150-tenant plan: who each tenant is, what it subscribes to and where it is in its lifecycle.
 * Pure and deterministic — `all()` returns the same list for the same config, with no database access.
 *
 * Lifecycle map (subscription state → tenant status):
 *   trialing → trial · active → active · past_due → suspended · expired → expired · canceled → deactivated
 * Every (plan × state) pair is covered first; the remainder is filled from a weighted pool.
 */
final class TenantBlueprints
{
    /** weights for the remainder after full plan×state coverage */
    private const STATE_WEIGHTS = ['trialing' => 10, 'active' => 20, 'expired' => 7, 'past_due' => 6, 'canceled' => 7];

    private const TENANT_STATUS = ['trialing' => 'trial', 'active' => 'active', 'past_due' => 'suspended', 'expired' => 'expired', 'canceled' => 'deactivated'];

    private const PLAN_SETS = [
        'hrms' => [['hrms-starter'], ['hrms-professional'], ['hrms-enterprise']],
        'tms' => [['tms-starter'], ['tms-professional'], ['tms-enterprise']],
        'both' => [
            ['starter'], ['pro'], ['business'], ['enterprise'],
            ['tms-starter', 'hrms-starter'], ['tms-professional', 'hrms-professional'], ['tms-enterprise', 'hrms-enterprise'],
            ['tms-starter', 'hrms-professional'], ['tms-professional', 'hrms-enterprise'], ['tms-enterprise', 'hrms-starter'],
            ['tms-professional', 'hrms-starter'], ['tms-starter', 'hrms-enterprise'], ['tms-enterprise', 'hrms-professional'],
        ],
    ];

    /** Larger plans carry larger organisations. [min, max] before clamping to config('demo_seed.users'). */
    private const SIZE_BY_TIER = ['starter' => [25, 60], 'professional' => [40, 120], 'pro' => [40, 120], 'business' => [80, 160], 'enterprise' => [120, 200]];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $out = [];
        $names = [];
        $index = 0;

        foreach (['hrms', 'tms', 'both'] as $category) {
            $count = (int) config("demo_seed.tenants.$category");
            foreach (self::assignments($category, $count) as $i => [$planSlugs, $state]) {
                $out[] = self::blueprint($index, $category, $i, $planSlugs, $state, $names);
                $index++;
            }
        }

        return $out;
    }

    /** @return list<array{0: list<string>, 1: string}> */
    private static function assignments(string $category, int $count): array
    {
        $sets = self::PLAN_SETS[$category];
        $states = array_keys(self::STATE_WEIGHTS);
        $pairs = [];

        // 1. every plan-set × state, bundles first so each plan appears in every lifecycle state.
        $primary = $category === 'both' ? array_slice($sets, 0, 4) : $sets;
        foreach ($primary as $set) {
            foreach ($states as $state) {
                $pairs[] = [$set, $state];
            }
        }

        // 2. the rest: remaining plan-sets cycled against a weighted state pool.
        $rest = $category === 'both' ? array_slice($sets, 4) : $sets;
        $pool = [];
        foreach (self::STATE_WEIGHTS as $state => $weight) {
            array_push($pool, ...array_fill(0, $weight, $state));
        }
        for ($i = 0; count($pairs) < $count; $i++) {
            $pairs[] = [$rest[$i % count($rest)], $pool[($i * 7 + 3) % count($pool)]];
        }

        return array_slice($pairs, 0, $count);
    }

    /** @param array<string, true> $names */
    private static function blueprint(int $index, string $category, int $i, array $planSlugs, string $state, array &$names): array
    {
        mt_srand(config('demo_seed.seed') + $index * 7919);

        $industryKeys = array_keys(NameBank::INDUSTRIES);
        $industryKey = $industryKeys[($index * 7 + intdiv($index, 3)) % count($industryKeys)];
        [$label, $forms, $deptSet] = NameBank::INDUSTRIES[$industryKey];

        $name = null;
        for ($try = 0; $try < 50; $try++) {
            $prefix = NameBank::PREFIXES[($index * 5 + $try * 11) % count(NameBank::PREFIXES)];
            $form = $forms[($index + $try) % count($forms)];
            $candidate = "$prefix $label $form";
            if (! isset($names[$candidate])) {
                $name = $candidate;
                break;
            }
        }
        $name ??= "Demo Org {$index}"; // unreachable with 60 prefixes × 15 industries; keeps uniqueness if sizes are raised
        $names[$name] = true;

        $slug = Str::slug($name);
        $country = NameBank::COUNTRIES[$index % count(NameBank::COUNTRIES)];

        $tier = self::tier($planSlugs);
        [$lo, $hi] = self::SIZE_BY_TIER[$tier];
        $lo = max($lo, (int) config('demo_seed.users.min'));
        $hi = max($lo, min($hi, (int) config('demo_seed.users.max')));

        $products = match ($category) {
            'hrms' => ['hrms'],
            'tms' => ['tms'],
            default => ['tms', 'hrms'],
        };

        return [
            'index' => $index,
            'category' => $category,
            'name' => $name,
            'slug' => $slug,
            'domain' => "{$slug}.test",
            'industry' => $industryKey,
            'dept_set' => $deptSet,
            'country' => $country,
            'products' => $products,
            'plans' => $planSlugs,
            'state' => $state,
            'tenant_status' => self::TENANT_STATUS[$state],
            'users' => mt_rand($lo, $hi),
            'projects' => mt_rand((int) config('demo_seed.projects.min'), (int) config('demo_seed.projects.max')),
            'workspaces' => mt_rand(2, 5),
            'upgraded' => $state === 'active' && $index % 4 === 0,
        ];
    }

    /** @param list<string> $planSlugs */
    private static function tier(array $planSlugs): string
    {
        $best = 'starter';
        $rank = ['starter' => 1, 'professional' => 2, 'pro' => 2, 'business' => 3, 'enterprise' => 4];
        foreach ($planSlugs as $slug) {
            $t = Str::afterLast($slug, '-');
            $t = isset($rank[$t]) ? $t : $slug;
            if (($rank[$t] ?? 1) > $rank[$best]) {
                $best = $t;
            }
        }

        return $best;
    }
}
