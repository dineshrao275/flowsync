<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.4 — payslip reads and their ledger.
 *
 * The run grid is a runner tool (`hrms.payroll.run`); one payslip is an
 * all-viewer or self-with-view read; every read — grid row, show, download —
 * writes its access row; and the signed download works from a fresh session
 * on the central connection because the tenant id rides the signature.
 */
class HrmsPayslipAccessTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_runner_sees_the_grid_and_every_row_is_logged(): void
    {
        [$run, $first] = $this->calculatedRun(2);
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run']));

        $body = $this->getJson("/api/hrms/payroll/runs/{$run->id}/payslips")->assertOk()->json();

        $this->assertCount(2, $body['payslips']);
        $this->assertSame(2026, $body['run']['period_year']);
        $this->assertSame('37300.00', $body['payslips'][0]['net_pay']);

        $this->assertSame(2, $this->accessRows()->where('action', 'view')->count());
    }

    public function test_the_grid_is_a_runner_tool(): void
    {
        [$run] = $this->calculatedRun(1);
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson("/api/hrms/payroll/runs/{$run->id}/payslips")->assertForbidden();
    }

    public function test_an_all_viewer_reads_any_payslip_and_is_logged(): void
    {
        [$run, $first] = $this->calculatedRun(1);
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.view_all']));

        $body = $this->getJson("/api/hrms/payroll/payslips/{$first->id}")->assertOk()->json('payslip');

        $this->assertSame('37300.00', $body['net_pay']);
        $this->assertStringContainsString('signature=', $body['download_url']);

        $row = $this->accessRows($first)->where('action', 'view')->firstOrFail();
        $this->assertContains('net_pay', $row->fields);
        $this->assertNotContains('37300.00', $row->fields);
    }

    public function test_self_reads_own_but_not_anothers(): void
    {
        [$run, $first, $second] = $this->calculatedRun(2);

        $owner = $this->userWith(['hrms.view', 'hrms.payroll.view'], $first->employee);
        $this->actAs($owner);

        $this->getJson("/api/hrms/payroll/payslips/{$first->id}")->assertOk();
        $this->getJson("/api/hrms/payroll/payslips/{$second->id}")->assertForbidden();
    }

    public function test_self_without_the_view_permission_reads_nothing(): void
    {
        [$run, $first] = $this->calculatedRun(1);

        $owner = $this->userWith(['hrms.view'], $first->employee);
        $this->actAs($owner);

        $this->getJson("/api/hrms/payroll/payslips/{$first->id}")->assertForbidden();
    }

    public function test_a_signed_download_works_session_free_and_is_logged(): void
    {
        [$run, $first] = $this->calculatedRun(1);
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.view_all']));

        $url = $this->getJson("/api/hrms/payroll/payslips/{$first->id}")->json('payslip.download_url');

        $this->flushSession();
        \DB::setDefaultConnection(config('tenancy.system.connection'));

        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('37300.00', $response->streamedContent() ?? $response->getContent());
        $this->assertStringContainsString($first->employee->displayName(), $response->streamedContent() ?? $response->getContent());

        $this->assertSame(1, $this->accessRows($first)->where('action', 'download')->count());
    }

    public function test_an_anonymous_signed_link_downloads_nothing(): void
    {
        [$run, $first] = $this->calculatedRun(1);

        $url = URL::temporarySignedRoute('hrms.payslips.download', now()->addHour(), [
            'payslip' => $first->id,
            'tenant' => $this->acme()->id,
        ]);

        $this->get($url)->assertForbidden();
    }

    public function test_a_tampered_payslip_url_is_refused(): void
    {
        [$run, $first] = $this->calculatedRun(1);
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.view_all']));

        $url = $this->getJson("/api/hrms/payroll/payslips/{$first->id}")->json('payslip.download_url');

        $this->get(str_replace('tenant='.$this->acme()->id, 'tenant=999999', $url))->assertForbidden();
    }

    public function test_a_stranger_cannot_download_even_signed(): void
    {
        [$run, $first] = $this->calculatedRun(1);

        $stranger = $this->userWith(['hrms.view', 'hrms.payroll.view']);
        $url = URL::temporarySignedRoute('hrms.payslips.download', now()->addHour(), [
            'payslip' => $first->id,
            'tenant' => $this->acme()->id,
            'actor' => $stranger->id,
        ]);

        $this->get($url)->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    /**
     * A calculated August run with N priced employees.
     *
     * @return array{PayrollRun, Payslip, Payslip}
     */
    private function calculatedRun(int $count): array
    {
        $this->connectTenant('acme');

        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $this->pricedEmployee();
        }

        app(PayrollService::class)->calculate($run);

        $payslips = $run->refresh()->payslips()->with('employee')->orderBy('id')->get();

        return [$run->refresh(), $payslips[0], $payslips[1] ?? $payslips[0]];
    }

    private function pricedEmployee(): Employee
    {
        $employee = $this->makeEmployee();

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Access 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Access Owner {$sequence}",
            'email' => "access.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        return Employee::create([
            'employee_code' => 'EMP-ACC-'.$sequence,
            'name' => "Access Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
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

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Access User {$sequence}",
                'email' => "access.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Access Role {$sequence}",
            'slug' => "access-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @return Collection<int, HrmsDataAccessLog>
     */
    private function accessRows(?Payslip $payslip = null): Collection
    {
        $this->connectTenant('acme');

        $query = HrmsDataAccessLog::query()
            ->where('model', (new Payslip)->getMorphClass());

        if ($payslip !== null) {
            $query->where('record_id', $payslip->id);
        }

        return $query->get();
    }
}
