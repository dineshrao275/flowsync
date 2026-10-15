<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetCategory;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetService;
use App\Services\Hrms\OffboardingService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P14.2 — the register, its handovers, and the exit they gate.
 *
 * Codes stamp server-side; a second assignment (or any non-available
 * state) refuses; receipts come from the holder's hands alone; returns
 * restore the register with the condition reported; and an exit with an
 * open handover cannot clear until the handover closes — tested in both
 * directions, the cross-phase contract.
 */
class HrmsAssetServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_entering_an_asset_stamps_its_code(): void
    {
        $asset = app(AssetService::class)->create($this->assetData(), $this->makeUser());

        $this->assertMatchesRegularExpression('/^AST-\d{6}$/', $asset->asset_code);
        $this->assertSame('available', $asset->status->value);
    }

    public function test_a_second_handover_is_refused(): void
    {
        [$holder, $other] = [$this->makeEmployee(withUser: true), $this->makeEmployee(withUser: true)];
        $asset = $this->asset();
        $service = app(AssetAssignmentService::class);

        $assignment = $service->assign($asset, $holder, 'good', $this->makeUser());

        $this->assertSame('assigned', $asset->refresh()->status->value);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $holder->user_id)
            ->where('type', 'hrms.asset.assigned')
            ->exists());

        try {
            $service->assign($asset->refresh(), $other, 'good', $this->makeUser());
            $this->fail('A second handover opened.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->assertSame($assignment->id, $asset->refresh()->assignments()->where('status', 'active')->firstOrFail()->id);
    }

    public function test_only_the_holder_receipts(): void
    {
        [$holder, $stranger] = [$this->makeEmployee(withUser: true), $this->makeEmployee(withUser: true)];
        $assigner = $this->makeUser();
        $service = app(AssetAssignmentService::class);

        $assignment = $service->assign($this->asset(), $holder, 'good', $assigner);

        try {
            $service->acknowledge($assignment, $stranger->user);
            $this->fail('A stranger receipted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $service->acknowledge($assignment->refresh(), $holder->user);

        $this->assertNotNull($assignment->refresh()->acknowledged_at);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $assigner->id)
            ->where('type', 'hrms.asset.acknowledged')
            ->exists());
    }

    public function test_a_return_restores_the_register_with_its_condition(): void
    {
        [$holder] = [$this->makeEmployee(withUser: true)];
        $service = app(AssetAssignmentService::class);

        $assignment = $service->assign($this->asset(), $holder, 'good', $this->makeUser());
        $returned = $service->returnAsset($assignment, 'fair', 'Scuffed corner.', $this->makeUser());

        $this->assertSame('returned', $returned->status->value);
        $this->assertSame('fair', $returned->condition_in->value);
        $this->assertSame('available', $returned->asset->refresh()->status->value);
        $this->assertSame('fair', $returned->asset->refresh()->condition->value);
        $this->assertNull($returned->asset->refresh()->assigned_to_employee_id);

        try {
            $service->returnAsset($returned->refresh(), 'good', null, $this->makeUser());
            $this->fail('A closed handover returned twice.');
        } catch (ValidationException) {
        }
    }

    public function test_retiring_needs_the_asset_home_and_losses_close_the_row(): void
    {
        [$holder] = [$this->makeEmployee(withUser: true)];
        $assets = app(AssetService::class);
        $handovers = app(AssetAssignmentService::class);

        $asset = $this->asset();
        $assignment = $handovers->assign($asset, $holder, 'good', $this->makeUser());

        try {
            $assets->retire($asset->refresh(), $this->makeUser());
            $this->fail('A checked-out asset retired.');
        } catch (ValidationException) {
        }

        $assets->markLost($asset->refresh(), $this->makeUser());

        $this->assertSame('lost', $asset->refresh()->status->value);
        $this->assertSame('lost', $assignment->refresh()->status->value);

        $retired = $assets->retire($this->asset(), $this->makeUser());
        $this->assertSame('retired', $retired->status->value);
    }

    public function test_maintenance_parks_and_returns_to_service(): void
    {
        $assets = app(AssetService::class);
        $asset = $this->asset();

        $record = $assets->maintenance($asset, [
            'type' => 'repair', 'description' => 'New battery.', 'performed_at' => '2026-09-01',
        ], $this->makeUser());

        $this->assertSame('maintenance', $asset->refresh()->status->value);

        try {
            app(AssetAssignmentService::class)->assign($asset->refresh(), $this->makeEmployee(), 'good');
            $this->fail('A repair-bay asset changed hands.');
        } catch (ValidationException) {
        }

        $assets->closeMaintenance($record, $this->makeUser());

        $this->assertSame('available', $asset->refresh()->status->value);
    }

    public function test_an_exit_with_hardware_out_cannot_clear_until_it_returns(): void
    {
        [$holder] = [$this->makeEmployee(withUser: true)];
        $asset = $this->asset();
        $handovers = app(AssetAssignmentService::class);
        $exits = app(OffboardingService::class);

        $assignment = $handovers->assign($asset, $holder, 'good', $this->makeUser());
        $case = $exits->initiate($holder, '2026-12-31', 'resigned');

        $clearance = $exits->summary($case->fresh());
        $this->assertSame(1, $clearance->pending_assets_count);

        try {
            $exits->clear($case->fresh(), $this->makeUser());
            $this->fail('An exit cleared over open hardware.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('asset', $exception->errors()['form'][0] ?? '');
        }

        // Direction two: the handover closes, the checklist completes, the
        // exit signs.
        $handovers->returnAsset($assignment, 'good', null, $this->makeUser());

        foreach ($case->tasks()->open()->get() as $task) {
            $exits->completeTask($task, $this->makeUser());
        }

        $cleared = $exits->clear($case->fresh(), $this->makeUser());

        $this->assertTrue($cleared->dues_settled);
        $this->assertSame(0, $cleared->pending_assets_count);
    }

    public function test_the_overdue_command_nudges_once_a_week(): void
    {
        [$holder] = [$this->makeEmployee(withUser: true)];
        $assignment = app(AssetAssignmentService::class)->assign($this->asset(), $holder, 'good');

        // Backdate past the threshold: the handover is old, unacknowledged.
        $assignment->update(['assigned_at' => now()->subDays(30)]);
        $assignment->asset->update(['assigned_at' => now()->subDays(30)]);

        $this->artisan('hrms:assets-overdue', [
            '--tenant' => $this->acme()->id, '--days' => 14, '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->where('type', 'hrms.asset.return_overdue')->count());

        $this->artisan('hrms:assets-overdue', [
            '--tenant' => $this->acme()->id, '--days' => 14,
        ])->assertSuccessful();

        // The holder hears, plus whoever holds the manage permission in
        // the seeded roles — the pool nobody nudges is a pile nobody works.
        $holderNotified = UserNotification::query()
            ->where('type', 'hrms.asset.return_overdue')
            ->where('user_id', $holder->user_id)
            ->count();
        $this->assertSame(1, $holderNotified);
        $total = UserNotification::query()->where('type', 'hrms.asset.return_overdue')->count();
        $this->assertGreaterThanOrEqual(1, $total);

        $this->artisan('hrms:assets-overdue', [
            '--tenant' => $this->acme()->id, '--days' => 14,
        ])->assertSuccessful();
        $this->assertSame($total, UserNotification::query()->where('type', 'hrms.asset.return_overdue')->count());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function assetData(): array
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return [
            'name' => 'ThinkPad.',
            'category_id' => AssetCategory::query()->firstOrCreate(
                ['slug' => 'laptops'],
                ['name' => 'Laptops'],
            )->id,
            'brand' => 'Lenovo',
            'serial_number' => 'SN-'.$sequence,
        ];
    }

    private function asset(): Asset
    {
        $this->connectTenant('acme');

        return app(AssetService::class)->create($this->assetData());
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return User::create([
            'name' => "Asset User {$sequence}",
            'email' => "asset.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => 'EMP-ASV-'.$sequence,
            'name' => "Asset Service Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $withUser ? $this->makeUser()->id : null,
        ]);
    }
}
