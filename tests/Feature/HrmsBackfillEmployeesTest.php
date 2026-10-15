<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.7 — `hrms:backfill-employees`.
 *
 * The command is a data migration over other people's accounts, so what it
 * refuses to guess matters as much as what it writes: it does not fabricate a
 * joining date, an employment type or a personal email, and it does not
 * overwrite a login that already has an employment record.
 */
class HrmsBackfillEmployeesTest extends TestCase
{
    use IsolatesDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::on(app(TenantDatabaseManager::class)->centralConnectionName())
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function backfill(?Tenant $tenant = null, bool $dryRun = false)
    {
        return $this->artisan('hrms:backfill-employees', array_filter([
            '--tenant' => $tenant?->id,
            '--all' => $tenant ? null : true,
            '--dry-run' => $dryRun ?: null,
        ], fn ($v) => $v !== null));
    }

    private function employees(Tenant $tenant): int
    {
        return app(TenantDatabaseManager::class)
            ->using($tenant, fn () => Employee::query()->count());
    }

    public function test_it_creates_a_record_for_every_user_without_one(): void
    {
        $acme = $this->tenant('acme');
        $expected = app(TenantDatabaseManager::class)->using($acme, fn () => User::query()->count());

        $this->assertGreaterThan(0, $expected);
        $this->assertSame(0, $this->employees($acme));

        $this->backfill($acme)->assertSuccessful()->run();

        $this->assertSame($expected, $this->employees($acme));

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            foreach (User::query()->get() as $user) {
                $employee = $user->employee;

                $this->assertNotNull($employee, "User {$user->id} has no employment record");
                // The code is derived from the login id, so a re-run cannot hand
                // the same person a different code.
                $this->assertSame("EMP-{$user->id}", $employee->employee_code);
                $this->assertSame($user->name, $employee->name);
            }
        });
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $acme = $this->tenant('acme');

        $this->backfill($acme)->assertSuccessful()->run();
        $before = $this->snapshot($acme);

        $this->backfill($acme)
            ->expectsOutputToContain('Already linked')
            ->assertSuccessful()
            ->run();

        $this->assertSame($before, $this->snapshot($acme));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $acme = $this->tenant('acme');

        $this->backfill($acme, dryRun: true)
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful()
            ->run();

        $this->assertSame(0, $this->employees($acme));

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            $this->assertSame(0, HrmsAuditLog::query()->where('action', 'employee.backfilled')->count());
        });
    }

    public function test_the_default_user_is_backfilled_first(): void
    {
        $acme = $this->tenant('acme');

        $this->backfill($acme)->assertSuccessful()->run();

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            $default = User::defaultUser();

            $this->assertNotNull($default, 'The fixture tenant should have a default user');

            // The audit rows are written in insertion order, so the first one
            // names the record created first — which is what makes a run
            // interrupted partway have still covered the tenant owner.
            $first = HrmsAuditLog::query()
                ->where('action', 'employee.backfilled')
                ->orderBy('id')
                ->first();

            $this->assertNotNull($first);
            $this->assertSame($default->employee->id, $first->subject_id);
        });
    }

    public function test_it_invents_no_employment_facts(): void
    {
        $acme = $this->tenant('acme');

        $this->backfill($acme)->assertSuccessful()->run();

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            foreach (Employee::query()->get() as $employee) {
                // Active is the only field the backfill can know to be true.
                $this->assertSame('active', $employee->status->value);

                // `users.created_at` is when the account was made, which on an
                // imported tenant says nothing about when somebody started work,
                // and a wrong joining date silently moves a payslip's proration.
                $this->assertNull($employee->joining_date);

                // Payroll reads this; "full-time is the common case" is a guess
                // about a real person, not a fact.
                $this->assertNull($employee->employment_type_id);

                // Deliberately distinct from users.email: the personal address
                // is not the work login, and copying one into the other
                // conflates the two for every masked reader.
                $this->assertNull($employee->personal_email);

                $this->assertNull($employee->manager_id);
            }
        });
    }

    public function test_a_login_that_already_has_a_record_is_left_alone(): void
    {
        $acme = $this->tenant('acme');

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            $user = User::query()->orderBy('id')->first();

            $employee = new Employee;
            $employee->user_id = $user->id;
            $employee->employee_code = 'EMP-HAND-MADE';
            $employee->name = 'Do not touch me';
            $employee->status = EmployeeStatus::OnNotice;
            $employee->save();
        });

        $this->backfill($acme)->assertSuccessful()->run();

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            $employee = Employee::query()->where('employee_code', 'EMP-HAND-MADE')->firstOrFail();

            // Neither replaced by a backfilled row nor reset to active: the
            // record is the authority on this person, not the absence of one.
            $this->assertSame('Do not touch me', $employee->name);
            $this->assertSame('on_notice', $employee->status->value);
        });
    }

    public function test_it_falls_back_to_an_allocated_code_when_the_preferred_one_is_taken(): void
    {
        $acme = $this->tenant('acme');
        $dbm = app(TenantDatabaseManager::class);

        $userId = $dbm->using($acme, fn () => User::query()->where('is_default', true)->value('id'));

        $dbm->using($acme, function () use ($userId): void {
            // Somebody created through the service already holds the code the
            // backfill would prefer for this login. The uniqueness violation
            // means "this is already somebody", so the row has to be written
            // with an allocated code rather than aborting the whole run.
            $employee = new Employee;
            $employee->employee_code = "EMP-{$userId}";
            $employee->name = 'Service Created';
            $employee->status = EmployeeStatus::Active;
            $employee->save();
        });

        $this->backfill($acme)
            ->expectsOutputToContain('Reallocated codes')
            ->assertSuccessful()
            ->run();

        $dbm->using($acme, function () use ($userId): void {
            $backfilled = Employee::query()->where('user_id', $userId)->firstOrFail();

            $this->assertNotSame("EMP-{$userId}", $backfilled->employee_code);
            $this->assertStringStartsWith('EMP-', $backfilled->employee_code);
            $this->assertSame(1, Employee::query()->where('employee_code', "EMP-{$userId}")->count());
        });
    }

    public function test_it_writes_one_audit_row_per_created_record_with_no_actor(): void
    {
        $acme = $this->tenant('acme');

        $this->backfill($acme)->assertSuccessful()->run();

        app(TenantDatabaseManager::class)->using($acme, function (): void {
            $rows = HrmsAuditLog::query()->where('action', 'employee.backfilled')->get();

            $this->assertSame(Employee::query()->count(), $rows->count());

            foreach ($rows as $row) {
                // A command has no signed-in user behind it. Claiming one, or
                // leaving a placeholder actor, would put a false name in a
                // ledger whose entire purpose is attributing changes.
                $this->assertNull($row->actor_user_id);
                $this->assertSame(Employee::class, $row->subject_type);
                // Curated payload: a code and a status, never a name or an email.
                $this->assertSame(['employee_code', 'user_id', 'status'], array_keys($row->data['after']));
            }
        });
    }

    public function test_a_named_tenant_leaves_the_others_untouched(): void
    {
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $this->backfill($acme)->assertSuccessful()->run();

        $this->assertGreaterThan(0, $this->employees($acme));
        $this->assertSame(0, $this->employees($globex), 'The other tenant was modified');
    }

    public function test_all_covers_every_tenant_that_has_a_database(): void
    {
        $this->backfill()->assertSuccessful()->run();

        $this->assertGreaterThan(0, $this->employees($this->tenant('acme')));
        $this->assertGreaterThan(0, $this->employees($this->tenant('globex')));
    }

    public function test_it_requires_exactly_one_scope(): void
    {
        $this->artisan('hrms:backfill-employees')
            ->expectsOutputToContain('Pass exactly one of')
            ->assertFailed();

        $this->artisan('hrms:backfill-employees', ['--all' => true, '--tenant' => 1])
            ->expectsOutputToContain('Pass exactly one of')
            ->assertFailed();
    }

    public function test_an_unknown_tenant_is_refused(): void
    {
        $this->artisan('hrms:backfill-employees', ['--tenant' => 999999])
            ->expectsOutputToContain('No tenant matched')
            ->assertFailed();
    }

    /** @return array<string, mixed> */
    private function snapshot(Tenant $tenant): array
    {
        return app(TenantDatabaseManager::class)->using($tenant, fn () => [
            'employees' => Employee::query()
                ->orderBy('id')
                ->get(['id', 'user_id', 'employee_code', 'name', 'status'])
                ->toArray(),
            'audit' => HrmsAuditLog::query()
                ->where('action', 'employee.backfilled')
                ->count(),
        ]);
    }
}
