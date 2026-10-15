<?php

namespace Tests\Feature;

use App\Enums\Hrms\DataAccessAction;
use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryRevision;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\Hrms\Statutory\TdsProject;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P19.2 — the review pass, pinned.
 *
 * The sweep over every HRMS controller found no raw model leaks, no
 * sensitive values in notifications, logs or validation messages — but
 * four read paths showed pay or statutory figures with no `accessed()` row
 * behind them, and the analytics document reader named confidential rows
 * to readers without the sensitive permission. Each now logs its pull
 * (field names, never values) or filters its rows, pinned below.
 */
class HrmsSensitiveReadAuditTest extends TestCase
{
    use IsolatesDatabase;

    public function test_salary_and_revision_reads_log_their_fields(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.compensation.view']);
        $employee = $this->pricedEmployee();
        $this->actAs($reader);

        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertOk();
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/revisions")->assertOk();

        $salary = HrmsDataAccessLog::query()
            ->where('model', (new EmployeeSalaryStructure)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->firstOrFail();

        $this->assertSame(DataAccessAction::View, $salary->action);
        $this->assertContains('ctc_annual', $salary->fields);

        $revision = HrmsDataAccessLog::query()
            ->where('model', (new SalaryRevision)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->firstOrFail();

        $this->assertContains('to_ctc', $revision->fields);
    }

    public function test_tds_projection_reads_log_their_fields(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']);
        $project = $this->tdsProject();
        $this->actAs($reader);

        $this->getJson('/api/hrms/payroll/statutory/tds-projects')->assertOk();
        $this->getJson("/api/hrms/payroll/statutory/tds-projects/{$project->id}")->assertOk();

        $this->assertSame(2, HrmsDataAccessLog::query()
            ->where('model', (new TdsProject)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->where('action', DataAccessAction::View)
            ->count());
    }

    public function test_declaration_reads_log_their_fields(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']);
        $declaration = $this->declaration();
        $this->actAs($reader);

        $this->getJson('/api/hrms/payroll/statutory/declarations')->assertOk();
        $this->getJson("/api/hrms/payroll/statutory/declarations/{$declaration->id}")->assertOk();

        $row = HrmsDataAccessLog::query()
            ->where('model', (new StatutoryDeclaration)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->where('record_id', $declaration->id)
            ->firstOrFail();

        $this->assertContains('declared_amount', $row->fields);
    }

    public function test_confidential_documents_stay_out_of_analytics_without_the_permission(): void
    {
        $employee = $this->employee('EMP-SENS-DOC', 'Sensitive Doc');
        $this->uploadFor($employee, ['title' => 'Open Medical', 'confidential' => false]);
        $this->uploadFor($employee, ['title' => 'Sealed Record', 'confidential' => true]);

        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view', 'hrms.documents.view']));
        $plain = $this->getJson('/api/hrms/analytics/documents')->assertOk()->json();

        $titles = collect($plain['expiring'])->flatMap(fn ($bucket) => collect($bucket['items'] ?? [])->pluck('title'))->all();
        $this->assertContains('Open Medical', $titles);
        $this->assertNotContains('Sealed Record', $titles);

        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view', 'hrms.documents.view', 'hrms.documents.view_sensitive']));
        $sensitive = $this->getJson('/api/hrms/analytics/documents')->assertOk()->json();

        $titles = collect($sensitive['expiring'])->flatMap(fn ($bucket) => collect($bucket['items'] ?? [])->pluck('title'))->all();
        $this->assertContains('Sealed Record', $titles);
    }

    public function test_the_data_access_endpoint_is_gated_filtered_and_name_only(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.compensation.view']);
        $employee = $this->pricedEmployee();
        $this->actAs($reader);
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertOk();

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/audit/data-access')->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.audit.view']));
        $response = $this->getJson('/api/hrms/audit/data-access')->assertOk();

        $response->assertJsonStructure([
            'data_access_logs', 'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $row = $response->json('data_access_logs.0');
        $this->assertSame('view', $row['action']);
        $this->assertContains('ctc_annual', $row['fields']);
        $this->assertStringNotContainsString('600000', (string) json_encode($row));

        // The action filter speaks the enum: unknown values 422, and the
        // model filter narrows to the salary ledger.
        $this->getJson('/api/hrms/audit/data-access?action=burn')->assertStatus(422);
        $filtered = $this->getJson('/api/hrms/audit/data-access?model='.(new EmployeeSalaryStructure)->getMorphClass())->json();
        $this->assertGreaterThanOrEqual(1, $filtered['pagination']['total']);
        $this->assertContains('ctc_annual', $filtered['data_access_logs'][0]['fields']);
    }

    // ------------------------------------------------------------ helpers

    private function employee(string $code, string $name): Employee
    {
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => $code, 'name' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function pricedEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;
        $employee = $this->employee("EMP-SENS-PAY-{$sequence}", "Sensitive Pay {$sequence}");
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => "Sensitive {$sequence}", 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    private function tdsProject(): TdsProject
    {
        $this->connectTenant('acme');

        return TdsProject::create([
            'employee_id' => $this->employee('EMP-SENS-TDS', 'Sensitive Tds')->id,
            'fiscal_year' => 2026,
            'quarter' => 1,
            'declared_income' => '100000',
            'exempt_income' => '10000',
            'projected_income' => '90000',
            'tax_liability' => '5000',
            'tds_deducted' => '1000',
        ]);
    }

    private function declaration(): StatutoryDeclaration
    {
        $this->connectTenant('acme');

        return StatutoryDeclaration::create([
            'employee_id' => $this->employee('EMP-SENS-DEC', 'Sensitive Dec')->id,
            'fiscal_year' => 2026,
            'section' => '80c',
            'declared_amount' => '100000',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function uploadFor(Employee $employee, array $overrides = []): EmployeeDocument
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $type = DocumentType::create([
            'name' => "Sensitive Type {$sequence}",
            'slug' => "sensitive-type-{$sequence}",
            'category' => 'identity',
            'is_mandatory' => false,
            'requires_expiry' => false,
            'retention_months' => null,
            'is_sensitive' => false,
            'position' => $sequence * 10,
            'is_active' => true,
            'is_system' => false,
        ]);

        $path = "hrms/{$this->acme()->id}/{$employee->id}/sensitive-{$sequence}.pdf";
        Storage::disk('local')->put($path, 'sensitive-bytes');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'title' => "Sensitive {$sequence}",
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => "sensitive-{$sequence}.pdf",
            'mime' => 'application/pdf',
            'size' => 15,
            'status' => 'pending',
            'visibility' => 'hr',
            'confidential' => false,
            'source' => 'hr',
            'expires_at' => now()->addDays(10),
            ...$overrides,
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
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Sensitive Audit User {$sequence}",
            'email' => "sensitive.audit.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Sensitive Audit Role {$sequence}",
            'slug' => "sensitive-audit-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
