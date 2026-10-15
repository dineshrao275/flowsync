<?php

namespace App\Services\Hrms\Employee\Bulk;

use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\HrmsAuditLogger;
use App\Services\TenantLimits;
use App\Support\Hrms\CsvTable;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — bulk hire from CSV: parse → validate (preview) → commit.
 *
 * Columns: `name` (required) plus optional `personal_email`, `phone`,
 * `joining_date`, `department`, `designation`, `location`, `employment_type`,
 * `work_mode`, `manager_code`. Org values resolve against the tenant's own
 * catalogues by slug, code or name (case-insensitive) — an unknown value is a
 * row error, never a silently created catalogue entry. Every committed row goes
 * through `EmployeeService::create()` (code allocation, audit row, plan quota),
 * and the whole batch is quota-checked *before* the first write so a plan cap
 * cannot leave a half-imported file behind. Logins are deliberately not created
 * here: a hire's account is a separate decision with its own role ceiling.
 */
class EmployeeImporter
{
    public const MAX_ROWS = 500;

    public const SAMPLE = [
        ['name', 'personal_email', 'phone', 'joining_date', 'department', 'designation', 'location', 'employment_type', 'work_mode', 'manager_code'],
        ['Asha Rao', 'asha@example.com', '+91 98765 43210', '2026-11-03', 'engineering', 'Specialist', 'headquarters', 'full_time', 'hybrid', ''],
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly TenantLimits $limits,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function parse(UploadedFile $file): array
    {
        return CsvTable::read($file, ['name'], self::MAX_ROWS);
    }

    /**
     * Validate rows and resolve catalogue references.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, valid: int, invalid: int, free_seats: int|null}
     */
    public function validate(array $rows): array
    {
        $lookups = [
            'department' => $this->index(Department::query()->get(['id', 'name', 'slug'])->all(), ['slug', 'name']),
            'designation' => $this->index(Designation::query()->get(['id', 'name', 'slug'])->all(), ['slug', 'name']),
            'location' => $this->index(Location::query()->get(['id', 'name', 'slug'])->all(), ['slug', 'name']),
            'employment_type' => $this->index(EmploymentType::query()->get(['id', 'name', 'code'])->all(), ['code', 'name']),
        ];
        $managers = Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $code) => [Str::lower($code) => $id])->all();

        $out = [];
        foreach ($rows as $row) {
            $errors = [];
            $resolved = [];

            if (($row['name'] ?? '') === '' || mb_strlen($row['name']) > 255) {
                $errors[] = 'Name is required (max 255 characters).';
            }
            if (($row['personal_email'] ?? '') !== '' && ! filter_var($row['personal_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Personal email is not a valid address.';
            }
            if (($row['joining_date'] ?? '') !== '') {
                try {
                    $date = Carbon::createFromFormat('Y-m-d', $row['joining_date']);
                    $date->format('Y-m-d') === $row['joining_date'] || throw new \InvalidArgumentException;
                    $resolved['joining_date'] = $date->toDateString();
                } catch (\Throwable) {
                    $errors[] = 'Joining date must be YYYY-MM-DD.';
                }
            }
            if (($row['work_mode'] ?? '') !== '') {
                WorkMode::tryFrom(Str::lower($row['work_mode']))
                    ? $resolved['work_mode'] = Str::lower($row['work_mode'])
                    : $errors[] = "Unknown work mode '{$row['work_mode']}'.";
            }

            foreach (['department' => 'department_id', 'designation' => 'designation_id', 'location' => 'location_id', 'employment_type' => 'employment_type_id'] as $column => $field) {
                $value = $row[$column] ?? '';
                if ($value === '') {
                    continue;
                }
                $id = $lookups[$column][Str::lower($value)] ?? null;
                $id !== null ? $resolved[$field] = $id : $errors[] = "Unknown {$column} '{$value}'.";
            }

            $managerId = null;
            if (($row['manager_code'] ?? '') !== '') {
                $managerId = $managers[Str::lower($row['manager_code'])] ?? null;
                $managerId === null && $errors[] = "Unknown manager code '{$row['manager_code']}'.";
            }

            $out[] = [
                'line' => $row['line'],
                'name' => $row['name'] ?? '',
                'errors' => $errors,
                'data' => array_filter([
                    'name' => $row['name'] ?? '',
                    'personal_email' => $row['personal_email'] ?? '',
                    'phone' => $row['phone'] ?? '',
                ], fn ($v) => $v !== '') + $resolved,
                'manager_id' => $managerId,
            ];
        }

        $valid = count(array_filter($out, fn ($r) => $r['errors'] === []));

        return ['rows' => $out, 'valid' => $valid, 'invalid' => count($out) - $valid, 'free_seats' => $this->freeSeats()];
    }

    /**
     * Create the importable rows.
     *
     * @param  array<int, array<string, mixed>>  $validated  the output of validate()['rows']
     * @return array{created: int, skipped: int, employees: array<int, array<string, mixed>>}
     *
     * @throws ValidationException when rows are invalid (and `skipInvalid` is off) or the plan cap would be exceeded
     */
    public function commit(array $validated, bool $skipInvalid, ?User $actor): array
    {
        $good = array_values(array_filter($validated, fn ($r) => $r['errors'] === []));
        $bad = count($validated) - count($good);

        if ($bad > 0 && ! $skipInvalid) {
            throw ValidationException::withMessages(['file' => "{$bad} row(s) have errors. Fix them or import only the valid rows."]);
        }
        if ($good === []) {
            throw ValidationException::withMessages(['file' => 'No valid rows to import.']);
        }

        // Whole-batch quota: assertQuota fails when count >= limit, so ask for
        // "everything but the last one" — the last row then needs a free seat.
        $this->limits->assertQuota('employees', ['count' => $this->limits->currentCount('employees') + count($good) - 1]);

        $created = [];
        foreach ($good as $row) {
            $employee = $this->employees->create($row['data'], $actor);
            if ($row['manager_id'] !== null) {
                $employee = $this->employees->assignManager($employee, Employee::query()->find($row['manager_id']), $actor);
            }
            $created[] = ['line' => $row['line'], 'id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->name];
        }

        $this->audit->log(HrmsSetting::current(), 'employee.bulk_imported', null, [
            'created' => count($created),
            'skipped' => $bad,
            'codes' => array_slice(array_column($created, 'employee_code'), 0, 100),
        ], $actor);

        return ['created' => count($created), 'skipped' => $bad, 'employees' => $created];
    }

    private function freeSeats(): ?int
    {
        $tenantId = app(TenantContext::class)->currentId();
        $tenant = $tenantId === null ? null : Tenant::find($tenantId);
        $limit = $tenant === null ? null : $this->limits->limit($tenant, 'employees');

        return $limit === null ? null : max(0, (int) $limit - $this->limits->currentCount('employees'));
    }

    /**
     * Case-insensitive value → id map over the named columns.
     *
     * @param  array<int, object>  $models
     * @param  array<int, string>  $columns
     * @return array<string, int>
     */
    private function index(array $models, array $columns): array
    {
        $map = [];
        foreach ($models as $model) {
            foreach ($columns as $column) {
                if ($model->{$column} !== null && $model->{$column} !== '') {
                    $map[Str::lower((string) $model->{$column})] ??= $model->id;
                }
            }
        }

        return $map;
    }
}
