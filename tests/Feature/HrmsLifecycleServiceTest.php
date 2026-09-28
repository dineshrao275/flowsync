<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\DocumentService;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\Hrms\Lifecycle\DocumentRequestService;
use App\Services\Hrms\OffboardingService;
use App\Services\Hrms\OnboardingService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P4.2 — the onboarding/offboarding services.
 *
 * The migration’s contract is covered in `HrmsLifecycleTablesTest`; the HTTP
 * surface lands in P4.3. What is worth protecting *here* is the part only
 * the services can see: materialisation with correct due dates, the document
 * hinge (a document-category item raises a real request), the mandatory/waive
 * permission split, the clearance counters and the refusal to clear over
 * open items, and the terminate → auto-initiate wiring.
 */
class HrmsLifecycleServiceTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Document uploads path files under the central tenant id, so the
        // service needs a context even though these tests never touch HTTP.
        app(TenantContext::class)->setTenantId($this->acme()->id);
    }

    // ------------------------------------------------------- templates

    public function test_a_template_is_created_with_its_items_and_a_suffixed_slug(): void
    {
        $service = app(OnboardingService::class);

        $first = $service->createTemplate('New Hire', ['tasks' => [$this->task(['title' => 'Sign contract'])]]);
        $second = $service->createTemplate('New Hire');

        $this->assertSame('new-hire', $first->slug);
        $this->assertSame('new-hire-2', $second->slug);
        $this->assertCount(1, $first->tasks);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'onboarding.template_created')->exists());
    }

    public function test_a_template_with_no_name_is_a_422_not_a_slug_of_nothing(): void
    {
        $this->expectException(ValidationException::class);

        app(OnboardingService::class)->createTemplate('   ');
    }

    public function test_a_template_cannot_be_deleted_while_cases_run_from_it(): void
    {
        $service = app(OnboardingService::class);
        $template = $service->createTemplate('Doomed');
        $service->createCase($this->makeEmployee(), $template);

        try {
            $service->deleteTemplate($template);
            $this->fail('Deleting a template under a running case must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        // Retiring is the supported answer instead.
        $service->updateTemplate($template, 'Doomed', ['is_active' => false]);

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_a_template_item_cannot_be_removed_while_an_open_case_runs_it(): void
    {
        $service = app(OnboardingService::class);
        $template = $service->createTemplate('Fragile', [
            'tasks' => [$this->task(['title' => 'Mandatory item', 'is_mandatory' => true])],
        ]);
        $item = $template->tasks()->firstOrFail();
        $case = $service->createCase($this->makeEmployee(), $template);

        try {
            $service->removeTemplateTask($item);
            $this->fail('Removing an item under an open case must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        // Once the case is closed the catalogue is free again.
        foreach ($case->tasks()->get() as $task) {
            $service->completeTask($task, $this->admin());
        }
        $service->complete($case->fresh(), $this->admin());
        $service->removeTemplateTask($item->fresh());

        $this->assertNull($item->fresh());
    }

    public function test_template_items_reorder_gap_free(): void
    {
        $service = app(OnboardingService::class);
        $template = $service->createTemplate('Ordered', ['tasks' => [
            $this->task(['title' => 'First']),
            $this->task(['title' => 'Second']),
            $this->task(['title' => 'Third']),
        ]]);
        $ids = $template->tasks()->orderBy('position')->pluck('id')->all();

        $service->reorderTemplateTasks($template, [$ids[2], $ids[0], 999999]);

        // The foreign id names no sibling and is dropped; the omitted sibling
        // keeps its relative order at the end rather than vanishing.
        $this->assertSame(
            [$ids[2], $ids[0], $ids[1]],
            $template->tasks()->orderBy('position')->pluck('id')->all(),
        );
        $this->assertSame([1, 2, 3], $template->tasks()->orderBy('position')->pluck('position')->all());
    }

    // ------------------------------------------------- onboarding cases

    public function test_a_case_materialises_tasks_with_joining_relative_due_dates(): void
    {
        $service = app(OnboardingService::class);
        $employee = $this->makeEmployee(['joining_date' => '2026-10-05']);
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Before day one', 'due_offset_days' => -2]),
            $this->task(['title' => 'Day one', 'due_offset_days' => 0]),
            $this->task(['title' => 'Week one', 'due_offset_days' => 7, 'owner_scope' => 'employee']),
        ]]);

        $case = $service->createCase($employee, $template, $this->admin());
        $tasks = $case->tasks()->orderBy('position')->get();

        $this->assertSame('in_progress', $case->status->value);
        $this->assertNotNull($case->started_at);
        $this->assertSame(['2026-10-03', '2026-10-05', '2026-10-12'], $tasks->map(fn ($task) => $task->due_date->toDateString())->all());
        // Employee-scoped items belong to the hire themselves.
        $this->assertSame($employee->id, $tasks[2]->owner_employee_id);
        $this->assertNull($tasks[0]->owner_employee_id);
    }

    public function test_a_document_category_item_raises_a_real_request(): void
    {
        $service = app(OnboardingService::class);
        $employee = $this->makeEmployee();
        $template = $service->createTemplate('Hire', ['tasks' => [$this->task(['title' => 'Bring passport', 'category' => 'document'])]]);

        $case = $service->createCase($employee, $template, $this->admin());
        $requests = app(DocumentRequestService::class)->forCase($case);

        $this->assertCount(1, $requests);
        $this->assertSame('Bring passport', $requests[0]->title);
        $this->assertSame('onboarding', $requests[0]->source->value);
        $this->assertSame('pending', $requests[0]->status->value);
    }

    public function test_an_employee_gets_one_onboarding_case(): void
    {
        $service = app(OnboardingService::class);
        $employee = $this->makeEmployee();
        $template = $service->createTemplate('Hire');
        $service->createCase($employee, $template);

        try {
            $service->createCase($employee, $template);
            $this->fail('A second onboarding case must be refused, not 500 on the unique index.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_complete_and_waive_with_the_mandatory_split(): void
    {
        $service = app(OnboardingService::class);
        $owner = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee(['user_id' => $owner->id]);
        $template = $service->createTemplate('Hire', ['tasks' => [
            $this->task(['title' => 'Mandatory', 'is_mandatory' => true, 'owner_scope' => 'employee']),
            $this->task(['title' => 'Optional', 'owner_scope' => 'employee']),
        ]]);
        $case = $service->createCase($employee, $template);
        [$mandatory, $optional] = $case->tasks()->orderBy('position')->get()->all();

        // The owner completes their own items without any manage permission.
        $service->completeTask($optional, $owner);

        $this->assertSame('done', $optional->fresh()->status->value);
        $this->assertSame($owner->id, $optional->fresh()->completed_by);

        // Closing the case with a mandatory item open names the blocker.
        try {
            $service->complete($case->fresh());
            $this->fail('Mandatory open items must block completion.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mandatory', $exception->errors()['form'][0] ?? $exception->getMessage());
        }

        // The owner may not waive a mandatory item away themselves.
        try {
            $service->waiveTask($mandatory->fresh(), 'Do not feel like it.', $owner);
            $this->fail('Waiving mandatory without manage must be refused.');
        } catch (ValidationException) {
            // Expected.
        }

        $manager = $this->userWith(['hrms.view', 'hrms.onboarding.manage']);
        $service->waiveTask($mandatory->fresh(), 'Verified in person on day one.', $manager);

        $this->assertSame('waived', $mandatory->fresh()->status->value);

        $progress = $service->progress($case->fresh());

        $this->assertSame(['total' => 2, 'done' => 1, 'waived' => 1, 'open' => 0, 'mandatory_total' => 1, 'mandatory_open' => 0, 'percent' => 100], $progress);

        $service->complete($case->fresh(), $manager);

        $this->assertSame('completed', $case->fresh()->status->value);
        $this->assertNotNull($case->fresh()->completed_at);
    }

    public function test_a_stranger_may_not_complete_someone_elses_item(): void
    {
        $service = app(OnboardingService::class);
        $employee = $this->makeEmployee();
        $template = $service->createTemplate('Hire', ['tasks' => [$this->task(['owner_scope' => 'employee'])]]);
        $case = $service->createCase($employee, $template);
        $task = $case->tasks()->firstOrFail();

        try {
            $service->completeTask($task, $this->userWith(['hrms.view']));
            $this->fail('A stranger completing an owned item must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    // ------------------------------------------------ document requests

    public function test_the_request_lifecycle_from_ask_to_accepted(): void
    {
        $service = app(DocumentRequestService::class);
        $employee = $this->makeEmployee();
        $actor = $this->admin();

        $request = $service->requestFor($employee, ['title' => 'Bring your passport'], actor: $actor);

        $this->assertSame('pending', $request->status->value);

        Storage::fake('local');
        $document = $this->uploadFor($employee, $actor);

        $submitted = $service->submit($request, $document, $employee->user);

        $this->assertSame('submitted', $submitted->status->value);
        $this->assertSame($document->id, $submitted->document_id);

        $accepted = $service->accept($submitted->fresh(), $actor);

        $this->assertSame('accepted', $accepted->status->value);

        try {
            $service->waive($accepted->fresh(), 'Too late.', $actor);
            $this->fail('An accepted request is evidence; it cannot be waived.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_a_rejected_request_reopens_the_loop(): void
    {
        $service = app(DocumentRequestService::class);
        $employee = $this->makeEmployee();
        $request = $service->requestFor($employee, ['title' => 'Bring your passport']);

        Storage::fake('local');
        $service->submit($request, $this->uploadFor($employee), null);
        $rejected = $service->reject($request->fresh(), 'Unreadable scan.', $this->admin());

        $this->assertSame('rejected', $rejected->status->value);

        $resubmitted = $service->submit($rejected->fresh(), $this->uploadFor($employee), null);

        $this->assertSame('submitted', $resubmitted->status->value);
    }

    public function test_submit_refuses_someone_elses_file_and_accept_needs_a_file(): void
    {
        $service = app(DocumentRequestService::class);
        $request = $service->requestFor($this->makeEmployee(), ['title' => 'Bring your passport']);

        Storage::fake('local');

        try {
            $service->submit($request, $this->uploadFor($this->makeEmployee()), null);
            $this->fail('Filing someone else’s document must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_id', $exception->errors());
        }

        try {
            $service->accept($request->fresh(), $this->admin());
            $this->fail('Accepting with no file must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    // ------------------------------------------------------ offboarding

    public function test_initiate_builds_the_checklist_clearance_and_exit_date(): void
    {
        $service = app(OffboardingService::class);
        $employee = $this->makeEmployee();
        $actor = $this->admin();

        $case = $service->initiate($employee, '2026-12-31', 'resigned', 30, $actor);

        $this->assertSame('initiated', $case->status->value);
        $this->assertCount(5, $case->tasks);
        $this->assertSame(
            ['Return assigned assets', 'Submit pending documents', 'Settle leave encashment', 'Settle expense claims', 'Revoke system access'],
            $case->tasks->map(fn ($task) => $task->title)->all(),
        );
        $this->assertSame('2026-12-31', $employee->fresh()->exit_date->toDateString());

        $clearance = $case->clearance;

        $this->assertNotNull($clearance);
        $this->assertSame(1, $clearance->pending_assets_count);
        $this->assertSame(0, $clearance->pending_documents_count);
        $this->assertTrue($clearance->isBlocked(), 'One open asset item blocks the clearance.');
    }

    public function test_a_second_open_exit_is_refused_but_a_second_exit_is_not(): void
    {
        $service = app(OffboardingService::class);
        $employee = $this->makeEmployee();
        $first = $service->initiate($employee, '2026-12-31', 'resigned');

        try {
            $service->initiate($employee, '2027-01-31', 'resigned');
            $this->fail('Two open exits must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $service->cancel($first->fresh(), $this->admin());
        $second = $service->initiate($employee, '2027-01-31', 'resigned');

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_clear_refuses_over_open_items_and_names_them(): void
    {
        $service = app(OffboardingService::class);
        $employee = $this->makeEmployee();
        $case = $service->initiate($employee, '2026-12-31', 'resigned', actor: $this->admin());

        try {
            $service->clear($case->fresh(), $this->admin());
            $this->fail('Clearing over open items must be refused.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['form'][0] ?? '';
            $this->assertStringContainsString('asset', $message);
        }

        // Returning the asset unblocks that counter; the documents counter is
        // already zero with no open asks.
        $assetTask = $case->tasks()->where('category', 'asset')->firstOrFail();
        $service->completeTask($assetTask, $this->admin());

        foreach ($case->tasks()->where('category', '!=', 'asset')->get() as $task) {
            $service->completeTask($task, $this->admin());
        }

        $cleared = $service->clear($case->fresh(), $this->admin());

        $this->assertTrue($cleared->dues_settled);
        $this->assertSame($this->admin()->id, $cleared->cleared_by_user_id);
        $this->assertNotNull($cleared->cleared_at);

        $service->complete($case->fresh(), $this->admin());

        $this->assertSame('completed', $case->fresh()->status->value);
    }

    public function test_complete_before_clear_is_refused(): void
    {
        $service = app(OffboardingService::class);
        $case = $service->initiate($this->makeEmployee(), '2026-12-31', 'resigned');

        try {
            $service->complete($case->fresh(), $this->admin());
            $this->fail('Completing an unsigned clearance must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_terminating_opens_the_exit_run(): void
    {
        $employee = $this->makeEmployee();
        $actor = $this->admin();

        app(EmployeeService::class)->terminate($employee, 'Role eliminated.', null, $actor);

        $case = OffboardingCase::where('employee_id', $employee->id)->open()->first();

        $this->assertNotNull($case, 'Ending an employment must open its exit run.');
        $this->assertCount(5, $case->tasks);
        $this->assertSame(
            $employee->fresh()->exit_date->toDateString(),
            $case->last_working_day->toDateString(),
            'The exit run’s last day is the stamped exit date, not a second guess.',
        );
    }

    public function test_terminating_with_an_open_exit_does_not_duplicate_it(): void
    {
        $service = app(OffboardingService::class);
        $employee = $this->makeEmployee();
        $service->initiate($employee, '2026-12-31', 'resigned');

        app(EmployeeService::class)->terminate($employee, 'Role eliminated.', null, $this->admin());

        $this->assertSame(1, OffboardingCase::where('employee_id', $employee->id)->count());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LC-'.$sequence,
            'name' => "Lifecycle Person {$sequence}",
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    /**
     * A user holding exactly the given permissions, on a fresh role so no
     * other test’s role is touched.
     *
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Lifecycle User {$sequence}",
            'email' => "lifecycle.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Lifecycle Role {$sequence}",
            'slug' => "lifecycle-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(array $overrides = []): array
    {
        return [
            'title' => 'Do the thing',
            'category' => 'task',
            'owner_scope' => 'hr',
            ...$overrides,
        ];
    }

    private function uploadFor(Employee $employee, ?User $actor = null): EmployeeDocument
    {
        $type = DocumentType::query()->where('slug', 'passport')->firstOrFail();

        return app(DocumentService::class)->upload(
            $employee,
            $type,
            UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
            ['title' => 'Passport'],
            $actor,
        );
    }
}
