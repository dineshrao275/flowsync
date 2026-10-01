<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Asset\AssetCategory;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetService;
use App\Services\Hrms\Document\DocumentLifecycle;
use App\Services\Hrms\OffboardingService;
use App\Services\Hrms\Performance\PerformanceCycleService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P15.3 — the taxonomy top-ups fire where they belong.
 *
 * Document decisions reach the file's owner; a blocked exit nudges HR
 * managers on day one; a live cycle tells its participants; and sharing a
 * review tells its owner. Each skips its actor and each carries the ids
 * its deep link needs.
 */
class HrmsTaxonomyTest extends TestCase
{
    use IsolatesDatabase;

    public function test_document_decisions_reach_the_owner(): void
    {
        [$manager, $report] = $this->reportingLine();

        $verified = $this->file($report);
        app(DocumentLifecycle::class)->verify($verified, $manager->user);

        $this->assertNotified($report->user->id, 'hrms.document.verified', ['document_id' => $verified->id]);

        $rejected = $this->file($report);
        app(DocumentLifecycle::class)->reject($rejected, 'Blurry scan.', $manager->user);

        $this->assertNotified($report->user->id, 'hrms.document.rejected', ['document_id' => $rejected->id]);

        // The actor never toasts themselves.
        $this->assertFalse(UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->whereIn('type', ['hrms.document.verified', 'hrms.document.rejected'])
            ->exists());
    }

    public function test_a_blocked_exit_nudges_hr_on_day_one(): void
    {
        [$manager, $report] = $this->reportingLine();
        $hr = $this->userWith(['hrms.view', 'hrms.offboarding.manage']);

        $asset = app(AssetService::class)->create([
            'name' => 'Laptop.',
            'category_id' => AssetCategory::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops'])->id,
            'serial_number' => 'SN-TAX-1',
        ]);
        app(AssetAssignmentService::class)->assign($asset, $report, 'good');

        app(OffboardingService::class)->initiate($report, '2026-12-31', 'resigned', null, $manager->user);

        $this->assertNotified($hr->id, 'hrms.offboarding.clearance_pending', ['employee_id' => $report->id]);
    }

    public function test_a_live_cycle_tells_its_participants(): void
    {
        [$manager, $report] = $this->reportingLine();

        $cycle = app(PerformanceCycleService::class)->createCycle([
            'name' => 'H1 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ]);

        PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $report->id,
            'title' => 'Cycle goal.',
            'metric_type' => 'manual',
            'weight' => '100',
            'status' => 'active',
        ]);

        app(PerformanceCycleService::class)->openCheckIn($cycle->refresh(), $manager->user);

        $this->assertNotified($report->user->id, 'hrms.performance.cycle_opened', ['performance_cycle_id' => $cycle->id]);
    }

    public function test_sharing_a_review_tells_its_owner(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'H2 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        $review = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/reviews", [
            'employee_id' => $report->id,
            'manager_rating' => 4,
        ])->assertCreated()->json('review');

        // Filing hidden notifies nobody; the release does.
        $this->assertFalse(UserNotification::query()
            ->where('user_id', $report->user->id)
            ->where('type', 'hrms.performance.review_shared')
            ->exists());

        $this->putJson("/api/hrms/performance/reviews/{$review['id']}", [
            'visibility_to_employee' => 'shared',
        ])->assertOk();

        $this->assertNotified($report->user->id, 'hrms.performance.review_shared', ['review_summary_id' => $review['id']]);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNotified(int $userId, string $type, array $data): void
    {
        $row = UserNotification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->first();

        $this->assertNotNull($row, "No {$type} notification for user {$userId}.");

        foreach ($data as $key => $value) {
            $this->assertSame($value, $row->data[$key] ?? null, "Payload key {$key} mismatch.");
        }
    }

    private function file(Employee $owner): EmployeeDocument
    {
        $this->connectTenant('acme');

        return EmployeeDocument::create([
            'employee_id' => $owner->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Passport.',
            'file_disk' => 'local',
            'file_path' => 'hrms/passport.txt',
            'original_name' => 'passport.txt',
            'mime' => 'text/plain',
            'size' => 8,
        ])->refresh();
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Taxonomy Manager', 'email' => 'taxonomy.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-TAX-MGR', 'name' => 'Taxonomy Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);

        $reportUser = User::create([
            'name' => 'Taxonomy Report', 'email' => 'taxonomy.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-TAX-REP', 'name' => 'Taxonomy Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);

        return [$manager, $report];
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Taxonomy User {$sequence}",
            'email' => "taxonomy.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Taxonomy Role {$sequence}",
            'slug' => "taxonomy-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
