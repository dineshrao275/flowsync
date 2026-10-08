<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\EmployeeCodeGenerator;
use Illuminate\Support\Collection;

/**
 * HRMS — gives every login that has no employment record one.
 *
 * An HRMS is unusable against a tenant that already has users: the directory is
 * empty, nobody appears in a report, and the 100 seeded dev tenants would need
 * 1,000 records created by hand. This is the migration that makes the module
 * usable on a tenant whose staff were onboarded before the module existed.
 *
 * Three deliberate choices, all of them about not inventing facts:
 *
 * - **It does not go through `EmployeeService::create()`.** That would apply the
 *   plan's `employees` quota, so a tenant on a 25-employee plan could not
 *   backfill its own 30 existing logins, and it would try to *create* logins
 *   that already exist. A backfill of existing accounts is a data migration, not
 *   1,000 hires.
 * - **It infers no joining date.** `users.created_at` is when the account was
 *   made, which on a seeded or imported tenant has nothing to do with when
 *   somebody started work, and a wrong joining date silently moves a payslip's
 *   proration. The column is nullable for exactly this case, so the field stays
 *   empty until a person sets it.
 * - **It infers no employment type.** Marking 1,000 people full-time because it
 *   is the common case would put a guess into a field payroll reads.
 *
 * The status history gets no row for the backfilled state either: the ledger
 * answers "who moved this person", and the answer here is a command line, which
 * the run summary reports instead.
 */
class EmployeeBackfill
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codes,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Create the missing employment records for one tenant.
     *
     * @return array{users: int, created: int, already_linked: int, reallocated: int}
     */
    public function run(): array
    {
        $summary = ['users' => 0, 'created' => 0, 'already_linked' => 0, 'reallocated' => 0];

        foreach ($this->candidates() as $user) {
            $summary['users']++;

            if ($user->employee()->exists()) {
                $summary['already_linked']++;

                continue;
            }

            $preferred = $this->preferredCode($user);
            $issued = null;

            // One transaction per user, not one for the run: a failure halfway
            // through a 1,000-row tenant must not roll back the other 999, and
            // the unique index is what actually guards the work_id/code pair, so
            // a re-run repairs whatever did not land.
            $employee = $this->codes->retrying(function (string $code) use ($user, &$issued) {
                $issued = $code;

                return $this->insert($user, $code);
            }, $preferred);

            $summary['created']++;
            $summary['reallocated'] += $issued === $preferred ? 0 : 1;

            $this->audit->log(
                $employee,
                'employee.backfilled',
                null,
                ['employee_code' => $employee->employee_code, 'user_id' => $user->id, 'status' => $employee->status->value],
            );
        }

        return $summary;
    }

    /**
     * Link one login to an employment record, creating it when missing.
     * Same mechanism as the batch run (deterministic code, collision
     * retry, audit row) — user creation and registration call this so no
     * login ever lands without a record to scope its self-service reads.
     */
    public function linkFor(User $user): Employee
    {
        if ($user->employee()->exists()) {
            return $user->employee;
        }

        $preferred = $this->preferredCode($user);
        $issued = null;

        $employee = $this->codes->retrying(function (string $code) use ($user, &$issued) {
            $issued = $code;

            return $this->insert($user, $code);
        }, $preferred);

        $this->audit->log(
            $employee,
            'employee.backfilled',
            null,
            ['employee_code' => $employee->employee_code, 'user_id' => $user->id, 'status' => $employee->status->value],
        );

        return $employee;
    }

    /**
     * What a run would do, without writing anything.
     *
     * @return array{users: int, created: int, already_linked: int, reallocated: int}
     */
    public function plan(): array
    {
        $summary = ['users' => 0, 'created' => 0, 'already_linked' => 0, 'reallocated' => 0];

        foreach ($this->candidates() as $user) {
            $summary['users']++;
            // The one thing a dry run cannot know is whether the preferred code
            // is free, so `reallocated` is left at zero rather than guessed.
            $user->employee()->exists()
                ? $summary['already_linked']++
                : $summary['created']++;
        }

        return $summary;
    }

    /**
     * Every login in the tenant, the default one first.
     *
     * The default user is the tenant owner, and putting it first means a run
     * interrupted partway has still backfilled the account that matters most.
     * `sortBy` is stable and the query is ordered by id, so the rest of the
     * order is untouched.
     *
     * @return Collection<int, User>
     */
    private function candidates(): Collection
    {
        return User::query()
            ->orderBy('id')
            ->get()
            ->sortBy(fn (User $user) => $user->is_default ? 0 : 1)
            ->values();
    }

    /**
     * `EMP-{user_id}` — deterministic, and derived from something that exists
     * before the row does.
     *
     * The code generator allocates by "one past the highest issued", which is
     * the right rule for a hire and the wrong one here: it would hand a
     * re-run different codes and leave a gap wherever a row is missing.
     */
    private function preferredCode(User $user): string
    {
        return 'EMP-'.$user->id;
    }

    private function insert(User $user, string $code): Employee
    {
        $employee = new Employee;
        $employee->user_id = $user->id;
        $employee->employee_code = $code;
        $employee->name = $user->name;
        // Active is the table default and the truthful reading of an account
        // that signs in today: nobody is on notice, suspended or exited. Set
        // explicitly rather than left to the column default, so the row says why.
        $employee->status = EmployeeStatus::Active;
        $employee->save();

        return $employee;
    }
}
