<?php

namespace Tests\Feature;

use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\Defaults\HrmsDefaultsProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.2a — the starter leave catalogue.
 *
 * Provisioning seeds five system types and links them to a default Standard
 * policy. Insert-only, like every other starter catalogue: a repair run
 * must add a catalogue row an old tenant is missing without resetting the
 * names a tenant chose for itself.
 */
class HrmsLeaveSeedTest extends TestCase
{
    use IsolatesDatabase;

    public function test_every_tenant_gets_the_starter_types_and_a_default_policy(): void
    {
        $types = LeaveType::query()->orderBy('position')->get();

        $this->assertSame(
            ['Annual Leave', 'Sick Leave', 'Unpaid Leave', 'Maternity Leave', 'Paternity Leave'],
            $types->pluck('name')->all(),
        );
        $this->assertTrue($types->every(fn (LeaveType $type): bool => $type->is_system));

        $policy = LeavePolicy::query()->default()->firstOrFail();

        $this->assertSame('Standard', $policy->name);
        $this->assertSame(
            $types->pluck('id')->sort()->values()->all(),
            $policy->types()->pluck('leave_types.id')->sort()->values()->all(),
        );
    }

    public function test_a_repair_run_adds_missing_rows_without_renaming_anything(): void
    {
        LeaveType::query()->where('code', 'annual')->update(['name' => 'Yearly Holiday']);

        app(HrmsDefaultsProvisioner::class)->provision();

        // The tenant's wording survives a repair: insert-only, never
        // updateOrCreate.
        $this->assertSame('Yearly Holiday', LeaveType::query()->where('code', 'annual')->value('name'));
        $this->assertSame(5, LeaveType::query()->count());
        $this->assertSame(1, LeavePolicy::query()->default()->count());
    }
}
