<?php

namespace Tests\Unit;

use App\Support\PermissionSelector;
use PHPUnit\Framework\TestCase;

/**
 * The selector is pure list algebra, so it is tested without the framework.
 * (This is the only test in the suite that does not need IsolatesDatabase.)
 */
class PermissionSelectorTest extends TestCase
{
    private PermissionSelector $selector;

    /** @var list<string> */
    private array $catalog = [
        'dashboard.view',
        'workspaces.view',
        'hrms.view',
        'hrms.employees.view',
        'hrms.employees.manage',
        'hrms.payroll.view',
        'hrms.payroll.run',
        'hrms.payroll.statutory.view',
        'hrms.payroll.statutory.manage',
        'hrms.compensation.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new PermissionSelector;
    }

    public function test_star_selects_the_whole_catalog(): void
    {
        $resolved = $this->selector->resolve(['*'], $this->catalog);

        $this->assertSame($this->catalog, $resolved);
    }

    public function test_star_wins_even_when_mixed_with_other_selectors(): void
    {
        $resolved = $this->selector->resolve(['hrms.view', '*'], $this->catalog);

        $this->assertSame($this->catalog, $resolved);
    }

    public function test_prefix_glob_selects_every_matching_slug(): void
    {
        $resolved = $this->selector->resolve(['hrms.*'], $this->catalog);

        $this->assertSame([
            'hrms.view',
            'hrms.employees.view',
            'hrms.employees.manage',
            'hrms.payroll.view',
            'hrms.payroll.run',
            'hrms.payroll.statutory.view',
            'hrms.payroll.statutory.manage',
            'hrms.compensation.manage',
        ], $resolved);
    }

    public function test_prefix_glob_does_not_match_the_bare_group_name(): void
    {
        // 'hrms.*' must mean "under hrms." — there is no bare `hrms` slug, but
        // guard it anyway so a future top-level `hrms` slug is not swept in.
        $catalog = ['hrms', 'hrms.view'];

        $this->assertSame(['hrms.view'], $this->selector->resolve(['hrms.*'], $catalog));
    }

    public function test_exclusion_removes_an_included_group(): void
    {
        $resolved = $this->selector->resolve(['hrms.*', '!hrms.payroll.*'], $this->catalog);

        $this->assertSame([
            'hrms.view',
            'hrms.employees.view',
            'hrms.employees.manage',
            'hrms.compensation.manage',
        ], $resolved);
    }

    public function test_exclusion_also_subtracts_nested_groups(): void
    {
        $resolved = $this->selector->resolve(
            ['hrms.*', '!hrms.payroll.statutory.*'],
            $this->catalog,
        );

        $this->assertNotContains('hrms.payroll.statutory.view', $resolved);
        $this->assertNotContains('hrms.payroll.statutory.manage', $resolved);
        $this->assertContains('hrms.payroll.view', $resolved);
    }

    public function test_exclusion_order_does_not_matter(): void
    {
        $a = $this->selector->resolve(['!hrms.payroll.*', 'hrms.*'], $this->catalog);
        $b = $this->selector->resolve(['hrms.*', '!hrms.payroll.*'], $this->catalog);

        $this->assertSame($a, $b);
    }

    public function test_exact_slugs_and_globs_combine(): void
    {
        $resolved = $this->selector->resolve(
            ['dashboard.view', 'hrms.payroll.*', '!hrms.payroll.run'],
            $this->catalog,
        );

        $this->assertSame([
            'dashboard.view',
            'hrms.payroll.view',
            'hrms.payroll.statutory.view',
            'hrms.payroll.statutory.manage',
        ], $resolved);
    }

    public function test_unknown_exact_slug_resolves_to_nothing(): void
    {
        $resolved = $this->selector->resolve(['hrms.nope.missing'], $this->catalog);

        $this->assertSame([], $resolved);
    }

    public function test_glob_matching_nothing_is_harmless(): void
    {
        $resolved = $this->selector->resolve(['hrms.nope.*', 'hrms.view'], $this->catalog);

        $this->assertSame(['hrms.view'], $resolved);
    }

    public function test_result_is_deduplicated(): void
    {
        $resolved = $this->selector->resolve(
            ['hrms.view', 'hrms.view', 'hrms.*'],
            $this->catalog,
        );

        $this->assertSame(array_values(array_unique($resolved)), $resolved);
    }

    public function test_exclusion_of_a_slug_never_included_is_a_no_op(): void
    {
        $resolved = $this->selector->resolve(['hrms.view', '!workspaces.view'], $this->catalog);

        $this->assertSame(['hrms.view'], $resolved);
    }

    public function test_result_order_follows_the_catalog_not_the_selector_order(): void
    {
        $resolved = $this->selector->resolve(
            ['hrms.payroll.run', 'dashboard.view', 'hrms.view'],
            $this->catalog,
        );

        $this->assertSame(['dashboard.view', 'hrms.view', 'hrms.payroll.run'], $resolved);
    }
}
