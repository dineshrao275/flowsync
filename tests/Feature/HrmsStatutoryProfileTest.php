<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Hrms\Statutory\StatutoryProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.5 — statutory identifiers, masked by default.
 *
 * Writes file identifiers (the full aadhaar survives as four digits, the
 * account as ciphertext); reads return masks plus presence booleans; only
 * the manage-gated reveal returns cleartext, and it writes its access row.
 * A person reads their own masked profile; anyone else's needs manage.
 */
class HrmsStatutoryProfileTest extends TestCase
{
    use IsolatesDatabase;

    public function test_manage_files_a_profile_and_reads_masks(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $body = $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", [
            'pan' => 'ABCDE1234F',
            'aadhaar' => '123456789012',
            'uan' => '101234567890',
            'bank_name' => 'Test Bank',
            'bank_account' => '123456789012',
            'bank_ifsc' => 'TEST0001234',
        ])->assertOk()->json('profile');

        $this->assertSame('XXXXX1234F', $body['pan']);
        $this->assertSame('9012', $body['aadhaar_last4']);
        $this->assertSame('****9012', $body['bank_account']);
        $this->assertTrue($body['has_pan']);
        $this->assertTrue($body['has_bank_account']);
        $this->assertFalse($body['has_pf_number']);
        $this->assertStringNotContainsString('ABCDE1234F', json_encode($body));
        $this->assertStringNotContainsString('123456789012', json_encode($body));
    }

    public function test_secrets_rest_encrypted_or_truncated(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", [
            'aadhaar' => '123456789012',
            'bank_account' => '123456789012',
        ])->assertOk();

        $stored = DB::table('statutory_profiles')->where('employee_id', $employee->id)->first();

        // Four digits are all that survives of the aadhaar; the account is
        // ciphertext, never the number.
        $this->assertSame('9012', $stored->aadhaar_last4);
        $this->assertStringNotContainsString('123456789012', (string) $stored->bank_account_encrypted);
    }

    public function test_a_person_reads_their_own_masked_profile(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));
        $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", ['pan' => 'ABCDE1234F'])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $employee));

        $body = $this->getJson("/api/hrms/payroll/statutory/profiles/{$employee->id}")->assertOk()->json('profile');

        $this->assertSame('XXXXX1234F', $body['pan']);
        $this->assertTrue($body['has_pan']);

        // Self reads masked; writes and reveals stay manage-alone.
        $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", ['pan' => 'ZZZZZ9999Z'])->assertForbidden();
        $this->postJson("/api/hrms/payroll/statutory/profiles/{$employee->id}/reveal", [])->assertForbidden();
    }

    public function test_a_stranger_reads_nothing(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));
        $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", ['pan' => 'ABCDE1234F'])->assertOk();

        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson("/api/hrms/payroll/statutory/profiles/{$employee->id}")->assertForbidden();
    }

    public function test_no_profile_reads_as_null_not_404(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $this->getJson("/api/hrms/payroll/statutory/profiles/{$employee->id}")
            ->assertOk()->assertJsonPath('profile', null);
    }

    public function test_a_reveal_returns_cleartext_and_logs_its_fields(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($manager = $this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));
        $this->putJson("/api/hrms/payroll/statutory/profiles/{$employee->id}", [
            'pan' => 'ABCDE1234F',
            'bank_account' => '123456789012',
        ])->assertOk();

        $body = $this->postJson("/api/hrms/payroll/statutory/profiles/{$employee->id}/reveal", [])
            ->assertOk()->json('profile');

        $this->assertSame('ABCDE1234F', $body['pan']);
        $this->assertSame('123456789012', $body['bank_account']);

        $this->connectTenant('acme');
        $row = HrmsDataAccessLog::query()
            ->where('model', (new StatutoryProfile)->getMorphClass())
            ->where('record_id', $body['id'])
            ->firstOrFail();

        $this->assertSame('view', $row->action->value);
        $this->assertContains('pan', $row->fields);
        $this->assertContains('bank_account_encrypted', $row->fields);
        $this->assertNotContains('uan', $row->fields);
    }

    // ------------------------------------------------------------ helpers

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Profile Owner {$sequence}",
            'email' => "profile.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-PRO-'.$sequence,
            'name' => "Profile Employee {$sequence}",
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
                'name' => "Profile User {$sequence}",
                'email' => "profile.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Profile Role {$sequence}",
            'slug' => "profile-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
