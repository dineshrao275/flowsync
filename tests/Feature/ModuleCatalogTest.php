<?php

namespace Tests\Feature;

use App\Services\ModuleTree;
use Tests\TestCase;

/**
 * The module catalog and its display metadata are two keys in one config file,
 * which is exactly the shape that drifts. This test is the lock: a module added
 * to `subscriptions.modules` without a label+group, or a label for a module that
 * no longer exists, fails here rather than rendering as a blank row in the admin
 * grid.
 */
class ModuleCatalogTest extends TestCase
{
    public function test_every_module_has_display_metadata(): void
    {
        $modules = config('subscriptions.modules');
        $meta = config('subscriptions.module_meta');

        $this->assertSame(
            [],
            array_values(array_diff($modules, array_keys($meta))),
            'Modules missing a label/group in subscriptions.module_meta',
        );
    }

    public function test_no_metadata_without_a_module(): void
    {
        $meta = config('subscriptions.module_meta');
        $modules = config('subscriptions.modules');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($meta), $modules)),
            'module_meta entries with no matching module in subscriptions.modules',
        );
    }

    public function test_every_module_has_a_non_empty_label_and_group(): void
    {
        foreach (config('subscriptions.module_meta') as $slug => $meta) {
            $this->assertArrayHasKey('label', $meta, $slug);
            $this->assertArrayHasKey('group', $meta, $slug);
            $this->assertNotSame('', trim((string) $meta['label']), $slug);
            $this->assertNotSame('', trim((string) $meta['group']), $slug);
        }
    }

    public function test_the_catalog_holds_the_full_hrms_set(): void
    {
        $hrms = array_values(array_filter(
            config('subscriptions.modules'),
            fn (string $m) => str_starts_with($m, 'hrms.'),
        ));

        $this->assertCount(22, $hrms, 'Plan 3.1 defines exactly 22 hrms.* module keys');
    }

    public function test_plan_module_lists_only_reference_known_modules(): void
    {
        $catalog = config('subscriptions.modules');

        foreach (config('subscriptions.plans') as $slug => $plan) {
            $unknown = array_values(array_diff($plan['limits']['modules'] ?? [], $catalog));

            $this->assertSame([], $unknown, "Plan {$slug} references unknown modules");
        }
    }

    public function test_plan_module_lists_have_no_duplicates(): void
    {
        foreach (config('subscriptions.plans') as $slug => $plan) {
            $modules = $plan['limits']['modules'] ?? [];

            $this->assertSame(
                array_values(array_unique($modules)),
                $modules,
                "Plan {$slug} has duplicate modules",
            );
        }
    }

    public function test_hrms_tiers_are_nested_monotonically(): void
    {
        $hrms = fn (string $slug) => array_values(array_filter(
            config("subscriptions.plans.{$slug}.limits.modules"),
            fn (string $m) => str_starts_with($m, 'hrms.'),
        ));

        $starter = $hrms('starter');
        $pro = $hrms('pro');
        $business = $hrms('business');
        $enterprise = $hrms('enterprise');

        // Each tier is a strict superset of the one below it.
        $this->assertSame([], array_diff($starter, $pro));
        $this->assertSame([], array_diff($pro, $business));
        $this->assertSame([], array_diff($business, $enterprise));

        $this->assertSame([], $starter, 'Starter ships no HRMS');
        $this->assertCount(12, $pro);
        $this->assertCount(18, $business);
        $this->assertCount(22, $enterprise);

        // Payroll is the Enterprise-only payoff.
        $this->assertNotContains('hrms.payroll', $business);
        $this->assertContains('hrms.payroll', $enterprise);
        $this->assertContains('hrms.payroll.statutory', $enterprise);
    }

    public function test_the_tree_groups_every_module_exactly_once(): void
    {
        $groups = app(ModuleTree::class)->groups();

        $seen = [];

        foreach ($groups as $group) {
            $this->assertNotSame('', $group['label']);

            foreach ($group['modules'] as $module) {
                $this->assertArrayNotHasKey($module['key'], $seen, 'Module appeared in two groups');
                $seen[$module['key']] = true;
            }
        }

        // Grouping deliberately reorders, so assert coverage, not sequence.
        $catalog = config('subscriptions.modules');
        sort($catalog);
        $seenKeys = array_keys($seen);
        sort($seenKeys);

        $this->assertSame($catalog, $seenKeys);
    }

    public function test_hrms_modules_get_their_own_groups(): void
    {
        $groups = collect(app(ModuleTree::class)->groups())->keyBy('key');

        $this->assertTrue($groups->has('HRMS · Core'));
        $this->assertTrue($groups->has('HRMS · Time & Attendance'));
        $this->assertTrue($groups->has('HRMS · Pay & Benefits'));

        $platform = collect($groups['Platform']['modules'])->pluck('key');
        $this->assertTrue($platform->every(fn (string $m) => ! str_starts_with($m, 'hrms.')));
    }

    public function test_depth_reflects_the_dotted_key(): void
    {
        $flat = collect(app(ModuleTree::class)->groups())
            ->flatMap(fn (array $g) => $g['modules'])
            ->keyBy('key');

        $this->assertSame(1, $flat['hrms.core']['depth']);
        $this->assertSame(2, $flat['hrms.attendance.remote']['depth']);
        $this->assertSame(2, $flat['hrms.payroll.statutory']['depth']);
        $this->assertSame(0, $flat['reports']['depth']);
    }

    public function test_an_unlabelled_module_still_appears_rather_than_vanishing(): void
    {
        config()->set('subscriptions.module_meta', []);
        config()->set('subscriptions.modules', ['hrms.orphan']);

        $groups = app(ModuleTree::class)->groups();

        $this->assertCount(1, $groups);
        $this->assertSame('Other', $groups[0]['key']);
        $this->assertSame('hrms.orphan', $groups[0]['modules'][0]['label']);
    }

    public function test_label_falls_back_to_the_slug(): void
    {
        $this->assertSame('Reports', app(ModuleTree::class)->label('reports'));
        $this->assertSame('hrms.made.up', app(ModuleTree::class)->label('hrms.made.up'));
    }
}
