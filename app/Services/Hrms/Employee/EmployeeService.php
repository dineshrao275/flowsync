<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Employee\ValueObjects\EmployeeProfile;
use App\Services\HrmsAuditLogger;
use App\Services\TenantLimits;
use App\Support\Hrms\EmployeeCodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — the employee record's use cases.
 *
 * Owns the rules that are true of *any* employee regardless of module: the code
 * is unique, an inline hire gets a login that can actually sign in, a status
 * change is a transition with a trail, and nobody can remove a manager's whole
 * reporting line by accident.
 *
 * Presentation is deliberately absent. `present()` and the PII masking are P2.4's
 * `Http/Resources/Hrms/Employee/EmployeeResource.php`, so this service answers
 * "is this allowed and what changed", never "what shape is the JSON". Likewise
 * the filter set is delegated to {@see EmployeeDirectoryQuery}, and the
 * whitelisted set of writable fields plus the curated audit payload live in
 * {@see EmployeeProfile}.
 *
 * @see EmployeeUserProvisioner for why a new hire's
 *      central routing row is written inside the tenant transaction.
 */
class EmployeeService
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codes,
        private readonly EmployeeUserProvisioner $provisioner,
        private readonly EmployeeDirectoryQuery $directory,
        private readonly EmployeeStatusTransition $transitions,
        private readonly ReportingLine $reporting,
        private readonly TenantLimits $limits,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Filtered, paginated directory of employees.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->directory->paginate($filters);
    }

    /**
     * How many rows the same filters match, for the pagination footer.
     *
     * @param  array<string, mixed>  $filters
     */
    public function countMatching(array $filters = []): int
    {
        return $this->directory->count($filters);
    }

    /**
     * The values the directory's filter bar needs.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function filterOptions(array $filters = []): array
    {
        return $this->directory->filterOptions($filters);
    }

    /**
     * One employee with everything a profile screen needs.
     */
    public function show(int $id): Employee
    {
        return Employee::with([
            'user', 'employmentType', 'manager.manager',
            'reports' => fn ($query) => $query->orderBy('name')->limit(50),
            'statusHistory' => fn ($query) => $query->limit(50),
        ])->findOrFail($id);
    }

    /**
     * Create an employee, optionally provisioning their login inline.
     *
     * Two shapes, one entry point, because the employee form is the only screen
     * in the product that creates a person, a login and a routing row at once:
     *
     *   - `['user_id' => 7, ...]` links an existing account;
     *   - `['name' => …, 'email' => …, 'password' => …, 'roles' => [...]]`
     *     creates the account too.
     *
     * Passing neither is legitimate, not an error: a service account has an
     * employment record and no login, and contractors and field staff have no
     * account to log in with.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on a duplicate email, a bad role, or a plan limit
     */
    public function create(array $data, ?User $actor = null): Employee
    {
        $this->limits->assertQuota('employees');

        $profile = EmployeeProfile::from($data);
        // Provision the login *before* the employee row, deliberately. The
        // account and its central `tenant_users` routing row live on two
        // connections, so this cannot be one transaction and the order has to
        // be chosen: an employee whose routing row is missing is invisible to
        // the sign-in path and cannot be repaired from the UI, whereas a spare
        // account left behind by a later failure is a visible, deletable record.
        // This is a lesser evil, not an atomic guarantee — see the note on
        // {@see self::resolveUser()}.
        $user = $this->resolveUser($data, $actor);

        // The code is re-derived per attempt, so the whole write — not just the
        // code allocation — is what gets retried on a collision. Five exhausted
        // attempts leave the login above behind; that is the trade this ordering
        // takes, and it is why the quota check above happens first, where it can
        // still stop the create before anything is written.
        $employee = $this->codes->retrying(fn (string $code) => $this->insert($profile, $code, $user, $actor));

        $this->audit->log($employee, 'employee.created', null, EmployeeProfile::auditable($employee), $actor);

        return $employee->refresh();
    }

    /**
     * Update the mutable parts of a record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data, ?User $actor = null): Employee
    {
        $before = EmployeeProfile::auditable($employee);
        $profile = EmployeeProfile::from($data);

        if ($profile->isEmpty()) {
            return $employee;
        }

        $employee->fill($profile->toArray());

        if (! $employee->isDirty()) {
            return $employee;
        }

        DB::transaction(fn () => $employee->save());

        $this->audit->log(
            $employee,
            'employee.updated',
            $before,
            EmployeeProfile::auditable($employee->refresh()),
            $actor,
        );

        return $employee;
    }

    /**
     * Move an employee to a new status, leaving a history row and an audit row.
     *
     * @param  array{effective_date?: string|null, reason?: string|null, note?: string|null}  $options
     *
     * @throws ValidationException when the status is unchanged
     */
    public function changeStatus(Employee $employee, EmployeeStatus $to, array $options = [], ?User $actor = null): Employee
    {
        $from = $employee->status;

        $employee = $this->transitions->apply($employee, $to, $options, $actor);

        $this->audit->log(
            $employee,
            'employee.status_changed',
            ['status' => $from->value],
            ['status' => $to->value],
            $actor,
        );

        return $employee;
    }

    /**
     * Re-parent an employee, refusing to build a cycle in the reporting line.
     *
     * A cycle is not cosmetic: every "everyone under X" walk and every
     * department-head approval step follows `manager_id`, so a loop is an
     * unbounded traversal in the middle of a payroll run.
     *
     * @throws ValidationException 422 on a self-assignment or a cycle
     */
    public function assignManager(Employee $employee, ?Employee $manager, ?User $actor = null): Employee
    {
        $before = ['manager_id' => $employee->manager_id];

        $employee = $this->reporting->reassign($employee, $manager);

        $this->audit->log(
            $employee,
            'employee.manager_assigned',
            $before,
            ['manager_id' => $employee->manager_id],
            $actor,
        );

        return $employee;
    }

    /**
     * End an employment.
     *
     * Separate from `changeStatus()` because it is the one transition a final
     * settlement keys off: it forces `terminated` rather than accepting a
     * target, and refuses to run twice, so a second call is an error instead of
     * a second exit date.
     *
     * @throws ValidationException 422 when the employee has already left
     */
    public function terminate(Employee $employee, ?string $reason = null, ?string $note = null, ?User $actor = null): Employee
    {
        $from = $employee->status;

        $employee = $this->transitions->terminate($employee, $reason, $note, $actor);

        $this->audit->log(
            $employee,
            'employee.status_changed',
            ['status' => $from->value],
            ['status' => $employee->status->value],
            $actor,
        );

        return $employee;
    }

    /**
     * Soft-delete an employment record.
     *
     * Soft, always, and not because the policy is uncertain — the policy already
     * decided. A record with payslips, leave history and attendance behind it is
     * not the caller's to erase, and a hard delete would remove the person from
     * every report they appear in while leaving the payroll rows that reference
     * them. A "delete" that only ever hides a row is the honest kind here.
     *
     * The linked login is deliberately left alone: it is a separate record with
     * its own lifecycle, and silently disabling a person's ability to sign in is
     * a much larger event than the one the caller asked for.
     */
    public function delete(Employee $employee, ?User $actor = null): void
    {
        $employee->delete();

        $this->audit->log(
            $employee,
            'employee.deleted',
            null,
            EmployeeProfile::auditable($employee),
            $actor,
        );
    }

    /**
     * Insert one employee under an already-allocated code.
     *
     * Private because the code is not the caller's to choose: it comes from
     * {@see EmployeeCodeGenerator::retrying()}, which only hands out a code
     * because it is about to insert the row itself.
     */
    private function insert(EmployeeProfile $profile, string $code, ?User $user, ?User $actor): Employee
    {
        return Employee::create([
            ...$profile->toArray(),
            'employee_code' => $code,
            'status' => $profile->initialStatus(),
            'work_mode' => $profile->workMode(),
            'user_id' => $user?->id,
            'created_by' => $actor?->id ?? $user?->id,
        ]);
    }

    /**
     * The account this employee will be reachable through, if any.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveUser(array $data, ?User $actor): ?User
    {
        if (! empty($data['user_id'])) {
            return User::findOrFail((int) $data['user_id']);
        }

        if (empty($data['email'])) {
            return null;
        }

        // The login is NOT inside the employee row's transaction, despite the
        // two being written one after the other. `users` is in the tenant
        // database and `tenant_users` routing is central, so the pair spans two
        // connections and no transaction covers both; `retrying()` only wraps the
        // employee insert. `TenantProvisioner::syncRouting()` reconciles a
        // routing row that went missing, which is what makes writing the
        // routing row first safe enough to do at all.
        return $this->provisioner->create([
            'name' => $data['name'] ?? '',
            'email' => (string) $data['email'],
            'password' => (string) ($data['password'] ?? ''),
            'roles' => (array) ($data['roles'] ?? []),
        ]);
    }
}
