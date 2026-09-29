<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.6a — rulebooks and exemption claims over HTTP.
 *
 * Configurations answer to manage alone, including deletion (locked
 * payslips price from snapshots, never by re-reading); claims file and
 * submit as self-or-manage but verify only through manage, with a foreign
 * proof file refused and every transition audited without amounts.
 */
class HrmsStatutoryConfigApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_non_manager_reaches_no_config_step(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/payroll/statutory/configurations')->assertForbidden();
        $this->postJson('/api/hrms/payroll/statutory/configurations', [
            'country' => 'IN',
            'name' => 'Nope',
            'code' => 'nope',
        ])->assertForbidden();
        $this->getJson('/api/hrms/payroll/statutory/declarations')->assertForbidden();
    }

    public function test_a_manager_runs_rulebooks_but_money_knobs_do_not_move(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $created = $this->postJson('/api/hrms/payroll/statutory/configurations', [
            'country' => 'IN',
            'name' => 'India Test',
            'code' => 'in-test',
            'config' => ['pf' => ['enabled' => true]],
        ])->assertCreated()->json('configuration');

        $this->assertSame('IN', $created['country']);

        // Code and country are create-only: a rulebook versions by
        // replacement, never by editing under priced snapshots.
        $this->putJson("/api/hrms/payroll/statutory/configurations/{$created['id']}", ['code' => 'in-test-2'])->assertStatus(422);
        $this->putJson("/api/hrms/payroll/statutory/configurations/{$created['id']}", ['country' => 'US'])->assertStatus(422);

        $this->putJson("/api/hrms/payroll/statutory/configurations/{$created['id']}", ['name' => 'India Test Two'])
            ->assertOk()->assertJsonPath('configuration.name', 'India Test Two');

        $this->deleteJson("/api/hrms/payroll/statutory/configurations/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('statutory_configurations', ['id' => $created['id']]);

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.configuration_created')->exists());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.configuration_deleted')->exists());
    }

    public function test_a_claim_walks_draft_to_verified(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $filed = $this->postJson('/api/hrms/payroll/statutory/declarations', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
            'section' => '80c',
            'declared_amount' => '100000',
        ])->assertCreated()->json('declaration');

        $this->assertSame('draft', $filed['status']);

        $this->postJson("/api/hrms/payroll/statutory/declarations/{$filed['id']}/submit", [])
            ->assertOk()->assertJsonPath('declaration.status', 'submitted');

        // Verifying a draft is refused: the queue moves one step at a time.
        $other = $this->postJson('/api/hrms/payroll/statutory/declarations', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
            'section' => '80d',
            'declared_amount' => '25000',
        ])->assertCreated()->json('declaration');
        $this->postJson("/api/hrms/payroll/statutory/declarations/{$other['id']}/verify", [])->assertStatus(422);

        $this->postJson("/api/hrms/payroll/statutory/declarations/{$filed['id']}/verify", [])
            ->assertOk()->assertJsonPath('declaration.status', 'verified');

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.declaration_verified')->exists());
    }

    public function test_a_person_files_their_own_claim_but_decides_nothing(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view'], $employee));

        $filed = $this->postJson('/api/hrms/payroll/statutory/declarations', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
            'section' => '80c',
            'declared_amount' => '100000',
        ])->assertCreated()->json('declaration');

        $this->postJson("/api/hrms/payroll/statutory/declarations/{$filed['id']}/submit", [])->assertOk();
        $this->postJson("/api/hrms/payroll/statutory/declarations/{$filed['id']}/verify", [])->assertForbidden();

        // And nobody else's claim is theirs to see.
        $stranger = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view'], $stranger));
        $this->getJson("/api/hrms/payroll/statutory/declarations/{$filed['id']}")->assertForbidden();
    }

    public function test_a_foreign_proof_file_is_refused(): void
    {
        $employee = $this->makeEmployee();
        $other = $this->makeEmployee();
        $this->connectTenant('acme');

        $document = EmployeeDocument::create([
            'employee_id' => $other->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Foreign file',
            'file_disk' => 'local',
            'file_path' => 'hrms/proof.txt',
            'original_name' => 'proof.txt',
            'mime' => 'text/plain',
            'size' => 4,
        ]);

        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $this->postJson('/api/hrms/payroll/statutory/declarations', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
            'section' => '80c',
            'declared_amount' => '100000',
            'proof_document_id' => $document->id,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------ helpers

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Config Owner {$sequence}",
            'email' => "config.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-CFG-'.$sequence,
            'name' => "Config Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $userId,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Config User {$sequence}",
                'email' => "config.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Config Role {$sequence}",
            'slug' => "config-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
