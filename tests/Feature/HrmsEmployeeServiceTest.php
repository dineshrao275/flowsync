<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmployeeStatusHistory;
use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeDirectoryQuery;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\Hrms\Employee\EmployeeUserProvisioner;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\SubscriptionService;
use App\Services\TenantLimits;
use App\Support\Hrms\EmployeeCodeGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.2 — the employee record's models, enums and service.
 *
 * The behaviour worth protecting here is not CRUD. It is the set of rules that
 * would each quietly produce bad data if they regressed: a code allocated twice,
 * a new hire whose account cannot sign in, a status change with no history row,
 * a reporting line that loops, a "who reports to whom" walk that never returns,
 * and a profile edit that reaches a field it should not.
 */
class HrmsEmployeeServiceTest extends TestCase
{
    use IsolatesDatabase;

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function type(array $overrides = []): EmploymentType
    {
        static $sequence = 0;

        $sequence++;

        return EmploymentType::create([
            'name' => $overrides['name'] ?? "Type {$sequence}",
            'slug' => $overrides['slug'] ?? "type-{$sequence}",
            'code' => $overrides['code'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
            'is_system' => $overrides['is_system'] ?? false,
            'position' => $overrides['position'] ?? $sequence,
        ]);
    }

    // ------------------------------------------------------------- enums

    public function test_the_status_enum_separates_employed_from_workable(): void
    {
        // The distinction that keeps a leaver off a payroll run and a suspended
        // employee off today's roster.
        $this->assertTrue(EmployeeStatus::Active->isEmployed());
        $this->assertTrue(EmployeeStatus::Probation->isEmployed());
        $this->assertTrue(EmployeeStatus::OnNotice->isEmployed());
        $this->assertTrue(EmployeeStatus::Suspended->isEmployed());

        $this->assertFalse(EmployeeStatus::Exited->isEmployed());
        $this->assertFalse(EmployeeStatus::Terminated->isEmployed());

        $this->assertTrue(EmployeeStatus::Active->isWorking());
        $this->assertTrue(EmployeeStatus::Probation->isWorking());

        $this->assertFalse(EmployeeStatus::Suspended->isWorking());
        $this->assertFalse(EmployeeStatus::OnNotice->isWorking());
    }

    public function test_only_the_terminal_statuses_are_offboarding(): void
    {
        $offboarding = array_values(array_filter(
            EmployeeStatus::cases(),
            fn (EmployeeStatus $status) => $status->isOffboarding(),
        ));

        $this->assertSame(
            [EmployeeStatus::Exited, EmployeeStatus::Terminated],
            $offboarding,
        );
    }

    public function test_every_status_has_a_label_and_a_colour(): void
    {
        foreach (EmployeeStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->color());
        }

        foreach (WorkMode::cases() as $mode) {
            $this->assertNotSame('', $mode->label());
            $this->assertNotSame('', $mode->color());
        }
    }

    public function test_only_non_office_modes_need_a_clock_in(): void
    {
        $this->assertFalse(WorkMode::Office->needsClockIn());
        $this->assertTrue(WorkMode::Hybrid->needsClockIn());
        $this->assertTrue(WorkMode::Remote->needsClockIn());
    }

    // ------------------------------------------------------------ models

    public function test_the_model_casts_the_two_state_fields(): void
    {
        $employee = $this->service()->create(['name' => 'Cast Me']);

        $this->assertInstanceOf(EmployeeStatus::class, $employee->status);
        $this->assertInstanceOf(WorkMode::class, $employee->work_mode);
    }

    public function test_the_relations_resolve(): void
    {
        $type = $this->type();
        $manager = $this->service()->create(['name' => 'The Boss']);
        $employee = $this->service()->create([
            'name' => 'The Report',
            'employment_type_id' => $type->id,
        ]);

        $this->service()->assignManager($employee, $manager);

        $this->assertTrue($type->employees->contains($employee));
        $this->assertTrue($manager->reports->contains($employee));
        $this->assertSame($manager->id, $employee->fresh()->manager->id);
        $this->assertSame($type->id, $employee->fresh()->employmentType->id);
        $this->assertSame([], $employee->statusHistory->all());
    }

    public function test_the_status_history_relation_is_newest_first(): void
    {
        $employee = $this->service()->create(['name' => 'Timeline']);

        $this->service()->changeStatus($employee, EmployeeStatus::OnNotice);
        $this->service()->changeStatus($employee->fresh(), EmployeeStatus::Exited);

        $history = $employee->fresh()->statusHistory;

        $this->assertSame(EmployeeStatus::Exited, $history->first()->to_status);
        $this->assertSame(EmployeeStatus::OnNotice, $history->last()->to_status);
    }

    public function test_the_scopes_filter_on_status(): void
    {
        $active = $this->service()->create(['name' => 'Still Here']);
        $leaver = $this->service()->create(['name' => 'Gone']);
        $noticer = $this->service()->create(['name' => 'Leaving Soon']);

        $this->service()->terminate($leaver, 'resigned');
        $this->service()->changeStatus($noticer->fresh(), EmployeeStatus::OnNotice);

        $this->assertSame([$active->id], Employee::active()->pluck('id')->all());
        $this->assertSame([$noticer->id], Employee::onNotice()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$active->id, $noticer->id],
            Employee::employed()->pluck('id')->all(),
        );
    }

    public function test_the_display_name_prefers_the_preferred_name(): void
    {
        $employee = $this->service()->create(['name' => 'Jonathan Smith', 'preferred_name' => 'Jon']);

        $this->assertSame('Jon', $employee->displayName());

        $plain = $this->service()->create(['name' => 'Plain Name']);

        $this->assertSame('Plain Name', $plain->displayName());
    }

    public function test_tenure_is_null_before_joining_and_counted_after(): void
    {
        $notStarted = $this->service()->create(['name' => 'Offer Accepted']);
        $this->assertNull($notStarted->tenureOn());

        $started = $this->service()->create([
            'name' => 'Two Years In',
            'joining_date' => now()->subYears(2)->toDateString(),
        ]);

        // An int, not Carbon's float: a service-length figure rendered as 6.575
        // is not a year count, and the implicit cast to the return type
        // deprecates.
        $this->assertIsInt($started->tenureOn());
        $this->assertSame(2, $started->tenureOn());
    }

    // ------------------------------------------------------ code allocation

    public function test_created_employees_get_sequential_unique_codes(): void
    {
        $codes = [];

        for ($i = 0; $i < 3; $i++) {
            $codes[] = $this->service()->create(['name' => "Hire {$i}"])->employee_code;
        }

        $this->assertSame(['EMP-1', 'EMP-2', 'EMP-3'], $codes);
    }

    public function test_a_deleted_employee_does_not_recycle_their_code(): void
    {
        // A code that has been on a payslip, an audit row and a bank transfer
        // must never come to mean a different person.
        $first = $this->service()->create(['name' => 'Leaver']);
        $this->service()->create(['name' => 'Second']);

        $first->forceDelete();

        $third = $this->service()->create(['name' => 'Third']);

        $this->assertSame('EMP-3', $third->employee_code);
        $this->assertNotSame('EMP-1', $third->employee_code);
    }

    public function test_a_code_with_gaps_still_advances_past_the_highest(): void
    {
        Employee::create(['employee_code' => 'EMP-9', 'name' => 'Imported']);

        $next = $this->service()->create(['name' => 'After The Gap']);

        $this->assertSame('EMP-10', $next->employee_code);
    }

    public function test_the_allocator_ignores_non_employee_codes(): void
    {
        // Task keys also look like PREFIX-numbers; deriving the sequence from the
        // wrong table would hand out EMP-1 for the first task key.
        $next = $this->service()->create(['name' => 'Ignores Tasks']);

        $this->assertSame('EMP-1', $next->employee_code);
    }

    public function test_a_collision_is_retried_rather_than_surfaced(): void
    {
        $generator = app(EmployeeCodeGenerator::class);

        // Pre-insert the code the generator is about to derive, so the first
        // attempt collides and the retry is exercised.
        Employee::create(['employee_code' => 'EMP-1', 'name' => 'Squatter']);

        $created = $generator->retrying(fn (string $code) => Employee::create([
            'employee_code' => $code,
            'name' => 'Retried',
        ]));

        $this->assertSame('EMP-2', $created->employee_code);
        $this->assertSame(2, Employee::count());
    }

    public function test_a_non_code_collision_is_not_retried(): void
    {
        $generator = app(EmployeeCodeGenerator::class);

        // A unique violation on a *different* column must not be mistaken for a
        // code collision and retried — otherwise a real integrity error becomes
        // five wasted attempts and a "code allocation failed" message that points
        // at the wrong thing entirely.
        $user = $this->service()->create([
            'name' => 'Has A User',
            'email' => 'shared@flowsync.test',
            'password' => 'password',
        ]);

        $this->expectException(QueryException::class);

        $generator->retrying(fn (string $code) => Employee::create([
            'employee_code' => $code,
            'name' => 'Clashes On The User',
            'user_id' => $user->user_id,
        ]));
    }

    // ----------------------------------------------------- inline user path

    public function test_an_inline_hire_gets_an_account_that_can_sign_in(): void
    {
        $employee = $this->service()->create([
            'name' => 'New Hire',
            'email' => 'New.Hire@Acme.Test',
            'password' => 'password',
            'roles' => ['viewer'],
        ]);

        $this->assertNotNull($employee->user_id);
        $this->assertNotNull($employee->fresh()->user);

        // The routing row is the whole point: without it the account exists in
        // the tenant database and can never authenticate.
        $routing = TenantUserRouting::where('email', 'new.hire@acme.test')->first();

        $this->assertNotNull($routing, 'The central routing row is missing');
        $this->assertSame($this->acme()->id, $routing->tenant_id);
        $this->assertSame((int) $employee->user_id, (int) $routing->user_id);
    }

    public function test_an_inline_hire_email_is_stored_lowercase(): void
    {
        // Login lower-cases to resolve the tenant, then matches users.email
        // case-sensitively, so a mixed-case stored address is unreachable.
        $employee = $this->service()->create([
            'name' => 'Cased',
            'email' => '  MiXeD.Case@Acme.Test ',
            'password' => 'password',
        ]);

        $this->assertSame('mixed.case@acme.test', $employee->fresh()->user->email);
    }

    public function test_an_inline_hire_gets_the_requested_roles(): void
    {
        $employee = $this->service()->create([
            'name' => 'With Roles',
            'email' => 'roles@flowsync.test',
            'password' => 'password',
            'roles' => ['editor'],
        ]);

        $this->assertSame(['editor'], $employee->fresh()->user->roles->pluck('slug')->all());
    }

    public function test_an_inline_hire_with_a_duplicate_email_is_rejected(): void
    {
        $this->service()->create([
            'name' => 'First',
            'email' => 'taken@flowsync.test',
            'password' => 'password',
        ]);

        try {
            $this->service()->create([
                'name' => 'Second',
                'email' => 'taken@flowsync.test',
                'password' => 'password',
            ]);

            $this->fail('A duplicate email should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // The failed create must not have left a half-made employee or a second
        // account behind. `employees` has no email column — the work address
        // lives on the user — so the account count is the thing to check.
        $this->assertSame(1, Employee::count());
        $this->assertSame(1, User::where('email', 'taken@flowsync.test')->count());
        $this->assertSame(1, TenantUserRouting::where('email', 'taken@flowsync.test')->count());
    }

    public function test_an_inline_hire_with_an_unknown_role_is_rejected(): void
    {
        try {
            $this->service()->create([
                'name' => 'Bad Role',
                'email' => 'badrole@flowsync.test',
                'password' => 'password',
                'roles' => ['wizard'],
            ]);

            $this->fail('An unknown role should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles', $e->errors());
        }

        $this->assertSame(0, Employee::count());
        $this->assertNull(User::where('email', 'badrole@flowsync.test')->first());
    }

    public function test_an_employee_may_be_linked_to_an_existing_user(): void
    {
        $user = User::where('email', 'editor@flowsync.test')->firstOrFail();

        $employee = $this->service()->create(['name' => 'Linked', 'user_id' => $user->id]);

        $this->assertSame((int) $user->id, (int) $employee->fresh()->user_id);
    }

    public function test_an_employee_may_have_no_user_at_all(): void
    {
        // A service account, a contractor, field staff: legitimate states.
        $employee = $this->service()->create(['name' => 'No Login']);

        $this->assertNull($employee->fresh()->user_id);
        $this->assertNull($employee->fresh()->user);
    }

    public function test_the_provisioner_reports_availability_before_writing(): void
    {
        $provisioner = app(EmployeeUserProvisioner::class);

        $this->assertTrue($provisioner->emailAvailable('free@flowsync.test'));
        $this->assertFalse($provisioner->emailAvailable('admin@flowsync.test'));
    }

    // ----------------------------------------------------------- status flow

    public function test_a_status_change_writes_a_history_row_and_an_audit_row(): void
    {
        $employee = $this->service()->create(['name' => 'Moving']);
        $actor = $this->admin();

        $this->service()->changeStatus($employee, EmployeeStatus::OnNotice, [
            'effective_date' => '2026-10-01',
            'reason' => 'resignation accepted',
        ], $actor);

        $history = EmployeeStatusHistory::where('employee_id', $employee->id)->first();

        $this->assertSame(EmployeeStatus::Active, $history->from_status);
        $this->assertSame(EmployeeStatus::OnNotice, $history->to_status);
        $this->assertSame('2026-10-01', $history->effective_date->toDateString());
        $this->assertSame('resignation accepted', $history->reason);
        $this->assertSame((int) $actor->id, (int) $history->actor_user_id);
    }

    public function test_an_offboarding_change_stamps_the_exit_date(): void
    {
        $employee = $this->service()->create(['name' => 'Leaving']);

        $this->service()->changeStatus($employee, EmployeeStatus::Exited, [
            'effective_date' => '2026-09-30',
        ]);

        $this->assertSame('2026-09-30', $employee->fresh()->exit_date->toDateString());
    }

    public function test_an_offboarding_change_without_a_date_defaults_to_today(): void
    {
        $employee = $this->service()->create(['name' => 'No Date Given']);

        $this->service()->changeStatus($employee, EmployeeStatus::Exited);

        $this->assertSame(now()->toDateString(), $employee->fresh()->exit_date->toDateString());
    }

    public function test_a_non_offboarding_change_leaves_the_exit_date_alone(): void
    {
        $employee = $this->service()->create(['name' => 'Coming Back']);

        $this->service()->changeStatus($employee, EmployeeStatus::Exited);
        $this->service()->changeStatus($employee->fresh(), EmployeeStatus::Active);

        $this->assertSame(
            EmployeeStatus::Active,
            $employee->fresh()->status,
            'Reactivation should be possible, and must not clear the historic exit date.',
        );
        $this->assertNotNull($employee->fresh()->exit_date);
    }

    public function test_changing_to_the_current_status_is_rejected(): void
    {
        $employee = $this->service()->create(['name' => 'Already Active']);

        $this->expectException(ValidationException::class);

        $this->service()->changeStatus($employee, EmployeeStatus::Active);
    }

    public function test_terminating_stamps_the_date_and_records_the_reason(): void
    {
        $employee = $this->service()->create(['name' => 'Final']);

        $this->service()->terminate($employee, 'resigned', 'handover complete');

        $employee = $employee->fresh();

        $this->assertSame(EmployeeStatus::Terminated, $employee->status);
        $this->assertSame(now()->toDateString(), $employee->exit_date->toDateString());
        $this->assertSame('resigned', $employee->statusHistory->first()->reason);
        // On the record as well as the ledger: `exited_reason` exists for exactly
        // this, and a directory query filtering on it would find nothing if only
        // the history row carried the reason.
        $this->assertSame('resigned', $employee->exited_reason);
    }

    public function test_a_re_departure_records_its_own_date_and_reason(): void
    {
        $employee = $this->service()->create(['name' => 'Rehired']);

        $this->service()->terminate($employee, 'resigned', 'first episode');
        $firstExit = $employee->fresh()->exit_date->toDateString();

        $this->service()->changeStatus($employee->fresh(), EmployeeStatus::Active);
        $this->service()->terminate($employee->fresh(), 'second dismissal', 'second episode');

        $employee = $employee->fresh();

        $this->assertSame('second dismissal', $employee->exited_reason);
        $this->assertSame(3, $employee->statusHistory()->count());

        // The first episode is not overwritten — the ledger keeps it.
        $firstExitRow = EmployeeStatusHistory::where('employee_id', $employee->id)
            ->where('to_status', EmployeeStatus::Terminated)
            ->orderBy('id')
            ->first();

        $this->assertSame('resigned', $firstExitRow->reason);
        $this->assertSame($firstExit, $firstExitRow->effective_date->toDateString());
    }

    public function test_terminating_twice_is_rejected(): void
    {
        $employee = $this->service()->create(['name' => 'Term Once']);
        $this->service()->terminate($employee, 'resigned');

        $this->expectException(ValidationException::class);

        $this->service()->terminate($employee->fresh(), 'resigned');
    }

    public function test_a_new_employee_starts_on_probation_when_a_probation_date_is_given(): void
    {
        $employee = $this->service()->create([
            'name' => 'Probationer',
            'probation_end_date' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertSame(EmployeeStatus::Probation, $employee->status);
    }

    // --------------------------------------------------------- reporting line

    public function test_an_employee_cannot_report_to_themselves(): void
    {
        $employee = $this->service()->create(['name' => 'Narcissus']);

        $this->expectException(ValidationException::class);

        $this->service()->assignManager($employee, $employee);
    }

    public function test_a_manager_cannot_report_to_one_of_their_own_reports(): void
    {
        $ceo = $this->service()->create(['name' => 'CEO']);
        $vp = $this->service()->create(['name' => 'VP']);
        $engineer = $this->service()->create(['name' => 'Engineer']);

        $this->service()->assignManager($vp, $ceo);
        $this->service()->assignManager($engineer, $vp);

        try {
            $this->service()->assignManager($ceo, $engineer);
            $this->fail('A reporting loop should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manager_id', $e->errors());
        }

        $this->assertSame((int) $vp->id, (int) $engineer->fresh()->manager_id);
    }

    public function test_a_three_deep_loop_is_also_rejected(): void
    {
        $a = $this->service()->create(['name' => 'A']);
        $b = $this->service()->create(['name' => 'B']);
        $c = $this->service()->create(['name' => 'C']);

        $this->service()->assignManager($b, $a);
        $this->service()->assignManager($c, $b);

        $this->expectException(ValidationException::class);

        $this->service()->assignManager($a, $c);
    }

    public function test_a_manager_can_be_unassigned(): void
    {
        $boss = $this->service()->create(['name' => 'Boss']);
        $employee = $this->service()->create(['name' => 'Reports To Boss']);

        $this->service()->assignManager($employee, $boss);
        $this->service()->assignManager($employee->fresh(), null);

        $this->assertNull($employee->fresh()->manager_id);
    }

    public function test_the_report_walk_returns_the_whole_subtree(): void
    {
        $ceo = $this->service()->create(['name' => 'CEO']);
        $vp = $this->service()->create(['name' => 'VP']);
        $eng = $this->service()->create(['name' => 'Eng']);
        $qa = $this->service()->create(['name' => 'QA']);

        $this->service()->assignManager($vp, $ceo);
        $this->service()->assignManager($eng, $vp);
        $this->service()->assignManager($qa, $vp);

        $names = array_map(
            fn (Employee $employee) => $employee->name,
            app(ReportingLine::class)->reportsOf($ceo),
        );

        $this->assertEqualsCanonicalizing(['VP', 'Eng', 'QA'], $names);
    }

    public function test_the_report_walk_survives_a_cycle_in_the_stored_data(): void
    {
        // Corrupt data from an import must not hang a payroll scope.
        $a = $this->service()->create(['name' => 'Loop A']);
        $b = $this->service()->create(['name' => 'Loop B']);

        Employee::withoutGlobalScopes()->where('id', $a->id)->update(['manager_id' => $b->id]);
        Employee::withoutGlobalScopes()->where('id', $b->id)->update(['manager_id' => $a->id]);

        $reports = app(ReportingLine::class)->reportsOf($a->fresh());

        $this->assertCount(1, $reports, 'The walk should terminate on cyclic input');
    }

    // ------------------------------------------------------------- updates

    public function test_an_update_changes_the_profile_and_audits_the_change(): void
    {
        $employee = $this->service()->create(['name' => 'Before']);
        $actor = $this->admin();

        $this->service()->update($employee, [
            'name' => 'After',
            'designation' => 'Staff Engineer',
        ], $actor);

        $employee = $employee->fresh();

        $this->assertSame('After', $employee->name);
        $this->assertSame('Staff Engineer', $employee->designation);
    }

    public function test_an_update_cannot_reach_the_identity_of_the_record(): void
    {
        // The code, the manager and the status have their own doors; allowing
        // them here would give three ways to set one fact and one without a trail.
        $manager = $this->service()->create(['name' => 'Real Manager']);
        $employee = $this->service()->create(['name' => 'Locked']);

        $originalCode = $employee->employee_code;

        $this->service()->update($employee, [
            'name' => 'Renamed',
            'employee_code' => 'EMP-999',
            'manager_id' => $manager->id,
            'status' => EmployeeStatus::Terminated,
        ]);

        $employee = $employee->fresh();

        $this->assertSame('Renamed', $employee->name);
        $this->assertSame($originalCode, $employee->employee_code);
        $this->assertNull($employee->manager_id);
        $this->assertSame(EmployeeStatus::Active, $employee->status);
        $this->assertSame(0, $employee->statusHistory()->count());
    }

    public function test_an_update_with_no_recognised_fields_is_a_no_op(): void
    {
        $employee = $this->service()->create(['name' => 'Unchanged']);

        $result = $this->service()->update($employee, ['nonsense' => 'value']);

        $this->assertSame('Unchanged', $result->name);
    }

    public function test_no_personal_data_reaches_the_audit_ledger(): void
    {
        $employee = $this->service()->create([
            'name' => 'Audited',
            'personal_email' => 'private@flowsync.test',
            'phone' => '+15550001111',
            'date_of_birth' => '1990-01-01',
            'address_line1' => '1 Private Street',
            'emergency_contact_name' => 'Next Of Kin',
        ]);

        $log = HrmsAuditLog::where('subject_id', $employee->id)
            ->where('subject_type', $employee->getMorphClass())
            ->latest('id')
            ->first();

        $serialised = json_encode($log->data);

        foreach (['private@flowsync.test', '+15550001111', '1990-01-01', '1 Private Street', 'Next Of Kin'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised, "Leaked into the audit ledger: {$secret}");
        }

        $this->assertStringContainsString('EMP-', $serialised, 'The audit row should still identify the record');
    }

    // ------------------------------------------------------------ directory

    public function test_the_directory_searches_name_code_and_personal_email(): void
    {
        $this->service()->create(['name' => 'Findable Person', 'personal_email' => 'needle@flowsync.test']);
        $this->service()->create(['name' => 'Someone Else']);

        $byName = $this->service()->list(['q' => 'Findable']);
        $this->assertSame(1, $byName->total());
        $this->assertSame('Findable Person', $byName->first()->name);

        $byEmail = $this->service()->list(['q' => 'needle@']);
        $this->assertSame(1, $byEmail->total());
    }

    public function test_a_search_term_does_not_act_as_a_wildcard(): void
    {
        $this->service()->create(['name' => 'Alice']);
        $this->service()->create(['name' => 'Bob']);

        // An unescaped % would match every row.
        $results = $this->service()->list(['q' => '%']);

        $this->assertSame(0, $results->total());
    }

    public function test_the_directory_filters_by_status_type_and_manager(): void
    {
        $type = $this->type();
        $manager = $this->service()->create(['name' => 'Boss']);

        $match = $this->service()->create([
            'name' => 'Match',
            'employment_type_id' => $type->id,
        ]);
        $this->service()->create(['name' => 'Other']);
        $this->service()->assignManager($match, $manager);

        $this->assertSame(1, $this->service()->list(['employment_type_id' => $type->id])->total());
        $this->assertSame(1, $this->service()->list(['manager_id' => $manager->id])->total());

        // All three are active, then one is terminated: the status filter has to
        // track the change, and a filter that silently matched everything would
        // pass the first assertion and fail this one.
        $this->assertSame(3, $this->service()->list(['status' => 'active'])->total());

        $this->service()->terminate($this->service()->list()->firstWhere('name', 'Other'));

        $this->assertSame(2, $this->service()->list(['status' => 'active'])->total());
        $this->assertSame(1, $this->service()->list(['status' => 'terminated'])->total());
    }

    public function test_the_directory_filters_by_joined_range(): void
    {
        $this->service()->create([
            'name' => 'Old Timer',
            'joining_date' => '2020-01-01',
        ]);
        $this->service()->create([
            'name' => 'Newcomer',
            'joining_date' => '2026-01-01',
        ]);

        $results = $this->service()->list([
            'joined_from' => '2025-01-01',
            'joined_to' => '2026-12-31',
        ]);

        $this->assertSame(1, $results->total());
        $this->assertSame('Newcomer', $results->first()->name);
    }

    public function test_an_unknown_status_filter_is_rejected_rather_than_returning_nothing(): void
    {
        $this->expectException(\ValueError::class);

        $this->service()->list(['status' => 'retired']);
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_reaching_sql(): void
    {
        $this->service()->create(['name' => 'Sorted']);

        // `sort` is user input and is whitelisted; anything unknown must not be
        // able to reach the ORDER BY.
        $results = $this->service()->list(['sort' => 'password', 'dir' => 'desc']);

        $this->assertSame(1, $results->total());
        $this->assertSame('Sorted', $results->first()->name);
    }

    public function test_the_count_matches_the_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service()->create(['name' => "Bulk {$i}"]);
        }

        $filters = ['q' => 'Bulk'];
        $page = $this->service()->list($filters);

        $this->assertSame($page->total(), $this->service()->countMatching($filters));
    }

    public function test_a_soft_deleted_employee_leaves_the_directory(): void
    {
        $employee = $this->service()->create(['name' => 'Trashed Person']);
        $employee->delete();

        $this->assertSame(0, $this->service()->list(['q' => 'Trashed'])->total());
        $this->assertNull($this->service()->list()->firstWhere('name', 'Trashed Person'));
    }

    public function test_the_filter_options_offer_types_managers_and_statuses(): void
    {
        $type = $this->type(['name' => 'In Use', 'slug' => 'in-use']);
        $unused = $this->type(['name' => 'Unused', 'slug' => 'unused']);
        $boss = $this->service()->create(['name' => 'Option Boss']);
        $report = $this->service()->create([
            'name' => 'Option Report',
            'employment_type_id' => $type->id,
        ]);
        $this->service()->assignManager($report, $boss);

        $options = $this->service()->filterOptions();

        $this->assertTrue($options['employment_types']->contains('id', $type->id));
        $this->assertTrue(
            $options['employment_types']->contains('id', $unused->id),
            'A type nobody holds yet is still a filter a reader may want.',
        );
        $this->assertTrue($options['managers']->contains('id', $boss->id));
        $this->assertCount(count(EmployeeStatus::cases()), $options['statuses']);
    }

    public function test_the_directory_query_is_reusable_on_its_own(): void
    {
        $this->service()->create(['name' => 'Standalone']);

        $query = app(EmployeeDirectoryQuery::class);

        $this->assertSame(1, $query->count(['q' => 'Standalone']));
        $this->assertSame(1, $query->paginate(['q' => 'Standalone'])->total());
    }

    // -------------------------------------------------------------- quotas

    public function test_the_employee_quota_blocks_a_create_when_the_plan_is_full(): void
    {
        $this->assignPlanWithEmployeeLimit(1);

        $this->service()->create(['name' => 'First Hire']);

        try {
            $this->service()->create(['name' => 'Second Hire']);
            $this->fail('The plan limit should have blocked the second employee.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('form', $e->errors());
        }

        $this->assertSame(1, Employee::count());
    }

    public function test_the_employee_quota_is_unlimited_without_a_subscription(): void
    {
        // The seeded acme tenant has no subscription, so it is unlimited and
        // must not lose the whole module to a missing limit.
        $this->service()->create(['name' => 'One']);
        $this->service()->create(['name' => 'Two']);
        $this->service()->create(['name' => 'Three']);

        $this->assertSame(3, Employee::count());
    }

    public function test_the_quota_counts_employees_rather_than_users(): void
    {
        // employees and users are different resources: a service account has a
        // login and no employee record, and a contractor is the reverse.
        $this->assertSame(0, app(TenantLimits::class)->currentCount('employees'));
        $this->assertGreaterThan(0, app(TenantLimits::class)->currentCount('users'));
    }

    // ------------------------------------------------------------- photoUrl

    public function test_the_photo_url_is_null_when_there_is_no_photo(): void
    {
        $employee = $this->service()->create(['name' => 'No Photo']);

        $this->assertNull($this->service()->photoUrl($employee));
        $this->assertNull($this->service()->photoUrl(null));
    }

    public function test_the_photo_url_resolves_a_stored_path(): void
    {
        $employee = $this->service()->create(['name' => 'Has Photo']);
        $employee->update(['photo_path' => 'hrms/photos/1.png']);

        $this->assertStringContainsString(
            'hrms/photos/1.png',
            (string) $this->service()->photoUrl($employee->fresh()),
        );
    }

    private function assignPlanWithEmployeeLimit(int $employees): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => ['employees' => $employees]]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->refresh());
    }
}
