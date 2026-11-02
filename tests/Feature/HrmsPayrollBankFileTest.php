<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Hrms\Statutory\StatutoryProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The payout file reveals decrypted bank account numbers, so it is pinned end to end: runner only, only
 * once a run is approved, an audited export, employees without bank details counted not listed, and a
 * cell a spreadsheet could execute is neutralised.
 */
class HrmsPayrollBankFileTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_non_runner_is_refused_and_a_draft_run_has_no_file_yet(): void
    {
        [, $runId] = $this->approvedRun();
        $this->actAs($this->userWith(['hrms.view']));
        $this->get("/api/hrms/payroll/runs/{$runId}/bank-file")->assertForbidden();

        $this->actAs($this->runner());
        $draft = $this->postJson('/api/hrms/payroll/runs', $this->period(['period_month' => 9, 'pay_period_start' => '2026-09-01', 'pay_period_end' => '2026-09-30']))
            ->assertCreated()->json('run');
        $this->get("/api/hrms/payroll/runs/{$draft['id']}/bank-file")->assertStatus(422);
    }

    public function test_an_approved_run_exports_the_decrypted_account_and_audits_the_export(): void
    {
        [$employee, $runId] = $this->approvedRun(bank: ['bank_name' => 'Test Bank', 'bank_account_encrypted' => '123456789012', 'bank_ifsc' => 'TEST0001234']);
        $this->actAs($this->runner());

        $response = $this->get("/api/hrms/payroll/runs/{$runId}/bank-file")->assertOk();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));

        $this->assertSame(['Employee code', 'Name', 'Bank', 'Account number', 'IFSC', 'Net pay', 'Narration'], $rows[0]);
        $this->assertSame([$employee->employee_code, $employee->name, 'Test Bank', '123456789012', 'TEST0001234'], array_slice($rows[1], 0, 5));
        $this->assertSame('Salary 2026-08', $rows[1][6]);
        $this->assertSame('0', $response->headers->get('X-Missing-Bank'));

        $log = HrmsDataAccessLog::query()->where('action', 'export')->latest('id')->first();
        $this->assertNotNull($log, 'The export is a data-access row.');
        $this->assertContains('bank_account', $log->fields);
    }

    public function test_the_neft_template_and_an_unknown_format(): void
    {
        [, $runId] = $this->approvedRun(bank: ['bank_name' => 'Test Bank', 'bank_account_encrypted' => '123456789012', 'bank_ifsc' => 'TEST0001234']);
        $this->actAs($this->runner());

        $neft = array_map('str_getcsv', array_filter(explode("\n", $this->get("/api/hrms/payroll/runs/{$runId}/bank-file?format=neft")->assertOk()->streamedContent())));
        $this->assertSame(['Beneficiary name', 'Beneficiary account', 'IFSC', 'Amount', 'Remarks'], $neft[0]);

        $this->getJson("/api/hrms/payroll/runs/{$runId}/bank-file?format=swift")->assertStatus(422);
    }

    public function test_someone_with_no_bank_details_is_counted_not_listed(): void
    {
        [, $runId] = $this->approvedRun(bank: null);
        $this->actAs($this->runner());

        $response = $this->get("/api/hrms/payroll/runs/{$runId}/bank-file")->assertOk();

        $this->assertSame('1', $response->headers->get('X-Missing-Bank'));
        $this->assertCount(1, array_filter(explode("\n", $response->streamedContent())), 'Only the header row remains.');
    }

    public function test_a_name_that_opens_like_a_formula_is_neutralised(): void
    {
        [$employee, $runId] = $this->approvedRun(bank: ['bank_name' => 'Test Bank', 'bank_account_encrypted' => '123456789012', 'bank_ifsc' => 'TEST0001234']);
        $employee->update(['name' => '=HYPERLINK("http://evil.test","x")']);
        $this->actAs($this->runner());

        $csv = $this->get("/api/hrms/payroll/runs/{$runId}/bank-file")->assertOk()->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, string>|null  $bank
     * @return array{Employee, int} the paid employee and an approved run id
     */
    private function approvedRun(?array $bank = ['bank_name' => 'Test Bank', 'bank_account_encrypted' => '999900001111', 'bank_ifsc' => 'TEST0009999']): array
    {
        $this->connectTenant('acme');
        $user = User::create(['name' => 'Bank Owner', 'email' => 'bank.owner@flowsync.test', 'password' => 'password']);
        $employee = Employee::create(['employee_code' => 'EMP-BANK-1', 'name' => 'Bank Employee', 'status' => EmployeeStatus::Active, 'user_id' => $user->id]);

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Bank 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10], ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20]],
        );
        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        if ($bank !== null) {
            StatutoryProfile::create(['employee_id' => $employee->id, ...$bank]);
        }

        $this->actAs($this->runner());
        $run = $this->postJson('/api/hrms/payroll/runs', $this->period())->assertCreated()->json('run');
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/calculate", [])->assertOk();
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/approve", [])->assertOk();

        return [$employee, (int) $run['id']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function period(array $overrides = []): array
    {
        return ['period_year' => 2026, 'period_month' => 8, 'pay_period_start' => '2026-08-01', 'pay_period_end' => '2026-08-31', 'pay_date' => '2026-09-05', ...$overrides];
    }

    private function runner(): User
    {
        return $this->userWith(['hrms.view', 'hrms.payroll.run']);
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

        $user = User::create(['name' => "Bank User {$sequence}", 'email' => "bank.user.{$sequence}@flowsync.test", 'password' => 'password']);
        $role = Role::create(['name' => "Bank Role {$sequence}", 'slug' => "bank-role-{$sequence}"]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
