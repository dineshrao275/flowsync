<?php

namespace Tests\Feature;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\ApprovalStepStatus;
use App\Enums\Hrms\ApproverType;
use App\Models\Hrms\Shared\Approval;
use App\Models\Hrms\Shared\ApprovalStep;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class HrmsApprovalTest extends TestCase
{
    use IsolatesDatabase;

    private function service(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** A throwaway subject model so approvals can be polymorphic over it. */
    private function subject(): Workspace
    {
        return Workspace::create([
            'name' => 'Leave Request Subject',
            'slug' => 'approval-subject',
            'created_by' => $this->user('admin@flowsync.test')->id,
        ]);
    }

    // ---------------------------------------------------------------- models

    public function test_the_settings_singleton_reads_nested_values(): void
    {
        $settings = HrmsSetting::current();

        $this->assertSame(1, $settings->id);
        $this->assertSame(480, $settings->setting('attendance.full_day_minutes'));
        $this->assertTrue($settings->isMasked());
        $this->assertNull($settings->setting('attendance.nope'));
        $this->assertSame('fallback', $settings->setting('attendance.nope', 'fallback'));
    }

    public function test_the_ledgers_have_no_updated_at_column(): void
    {
        $this->assertNull((new HrmsAuditLog)->updatedAt);
        $this->assertNull((new HrmsDataAccessLog)->updatedAt);
    }

    // -------------------------------------------------------------- lifecycle

    public function test_a_single_step_approval_resolves_on_approval(): void
    {
        $approver = $this->user('editor@flowsync.test');
        $requester = $this->user('admin@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::user($approver->id),
            $this->subject(),
            subjectLabel: 'Annual leave — Jane Doe',
            requester: $requester,
        );

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(1, $approval->current_step);
        $this->assertCount(1, $approval->steps);

        $resolved = $this->service()->approve($approval, $approver, 'Enjoy.');

        $this->assertSame(ApprovalStatus::Approved, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($approver->id, $resolved->resolved_by_user_id);
        $this->assertSame(ApprovalStepStatus::Approved, $resolved->steps->first()->status);
    }

    public function test_rejecting_stops_the_flow(): void
    {
        $approver = $this->user('editor@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::user($approver->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $rejected = $this->service()->reject($approval, $approver, 'Not this quarter.');

        $this->assertSame(ApprovalStatus::Rejected, $rejected->status);
        $this->assertSame('Not this quarter.', $rejected->decision_note);
        $this->assertSame(ApprovalStepStatus::Rejected, $rejected->steps->first()->status);
    }

    public function test_a_multi_step_chain_advances_sequentially(): void
    {
        $first = $this->user('editor@flowsync.test');
        $second = $this->user('viewer@flowsync.test');

        $approval = $this->service()->request(
            [ApproverSpec::user($first->id), ApproverSpec::user($second->id)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertCount(2, $approval->steps);
        $this->assertTrue($approval->isOpen());

        // First approver acts: still pending, now waiting on the second.
        $midway = $this->service()->approve($approval, $first);

        $this->assertSame(ApprovalStatus::Pending, $midway->status);
        $this->assertSame(2, $midway->current_step);

        // The first approver cannot act twice.
        $this->expectException(ValidationException::class);
        $this->service()->approve($midway, $first);
    }

    public function test_a_role_step_can_be_acted_on_by_any_holder_of_the_role(): void
    {
        $role = Role::where('slug', 'editor')->firstOrFail();
        $editor = $this->user('editor@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::role($role->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertTrue($this->service()->canAct($approval, $editor));
        $this->assertFalse($this->service()->canAct($approval, $this->user('viewer@flowsync.test')));

        $this->assertSame(
            ApprovalStatus::Approved,
            $this->service()->approve($approval, $editor)->status,
        );
    }

    // ----------------------------------------------------------------- skips

    public function test_a_step_with_no_approver_is_skipped_and_the_flow_continues(): void
    {
        $real = $this->user('editor@flowsync.test');

        $approval = $this->service()->request(
            [
                // Nobody to escalate to: a role step with no role id.
                new ApproverSpec(type: ApproverType::Role),
                ApproverSpec::user($real->id),
            ],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertSame(ApprovalStepStatus::Skipped, $approval->steps->first()->status);
        $this->assertSame(ApprovalStepStatus::Pending, $approval->steps->last()->status);
        $this->assertTrue($approval->isOpen());

        // The cursor lands on the first *actionable* step, so the skipped
        // leading step does not stall the chain.
        $this->assertSame(2, $approval->current_step);
        $this->assertSame(2, $approval->currentStepRecord()->step_order);
        $this->assertSame(
            ApprovalStatus::Approved,
            $this->service()->approve($approval, $real)->status,
        );
    }

    public function test_a_chain_where_every_step_is_skipped_resolves_immediately(): void
    {
        $approval = $this->service()->request(
            [new ApproverSpec(type: ApproverType::Role), new ApproverSpec(type: ApproverType::User)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertFalse($this->service()->canAct($approval, $this->user('editor@flowsync.test')));
    }

    public function test_a_manager_step_without_a_resolved_user_is_skipped(): void
    {
        $approval = $this->service()->request(
            [new ApproverSpec(type: ApproverType::Manager)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertSame(ApprovalStepStatus::Skipped, $approval->steps->first()->status);
    }

    public function test_a_manager_step_acts_for_the_resolved_user(): void
    {
        $manager = $this->user('editor@flowsync.test');

        $approval = $this->service()->request(
            [ApproverSpec::user($manager->id, employeeId: 42)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertSame(42, $approval->steps->first()->approver_employee_id);
        $this->assertTrue($this->service()->canAct($approval, $manager));
    }

    // ---------------------------------------------------------- authorisation

    public function test_a_non_approver_cannot_act(): void
    {
        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->expectException(ValidationException::class);
        $this->service()->approve($approval, $this->user('viewer@flowsync.test'));
    }

    public function test_a_tenant_admin_cannot_act_on_someone_elses_step(): void
    {
        // No administrator bypass: a stuck flow is cleared by cancelling it or
        // by resolving the real approver, not by an admin quietly approving.
        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertFalse($this->service()->canAct($approval, $this->user('admin@flowsync.test')));

        $this->expectException(ValidationException::class);
        $this->service()->approve($approval, $this->user('admin@flowsync.test'));
    }

    public function test_a_resolved_approval_cannot_be_acted_on_again(): void
    {
        $approver = $this->user('editor@flowsync.test');

        $approval = $this->service()->approve(
            $this->service()->request(
                ApproverSpec::user($approver->id),
                $this->subject(),
                requester: $this->user('admin@flowsync.test'),
            ),
            $approver,
        );

        $this->expectException(ValidationException::class);
        $this->service()->approve($approval, $approver);
    }

    // ------------------------------------------------------------- cancelling

    public function test_the_requester_can_cancel_a_pending_approval(): void
    {
        $requester = $this->user('admin@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $requester,
        );

        $cancelled = $this->service()->cancel($approval, $requester, 'Plans changed.');

        $this->assertSame(ApprovalStatus::Cancelled, $cancelled->status);
    }

    public function test_a_non_requester_cannot_cancel(): void
    {
        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->expectException(ValidationException::class);
        $this->service()->cancel($approval, $this->user('editor@flowsync.test'));
    }

    public function test_a_resolved_approval_cannot_be_cancelled(): void
    {
        $approver = $this->user('editor@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::user($approver->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );
        $approval = $this->service()->reject($approval, $approver);

        $this->expectException(ValidationException::class);
        $this->service()->cancel($approval, $approver);
    }

    public function test_a_request_without_steps_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->request([], $this->subject(), requester: $this->user('admin@flowsync.test'));
    }

    // ----------------------------------------------------------------- inbox

    public function test_pending_for_lists_only_the_actors_own_steps(): void
    {
        $editor = $this->user('editor@flowsync.test');
        $viewer = $this->user('viewer@flowsync.test');

        $approval = $this->service()->request(
            ApproverSpec::user($editor->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertCount(1, $this->service()->pendingFor($editor));
        $this->assertCount(0, $this->service()->pendingFor($viewer));

        $this->service()->approve($approval, $editor);

        // Resolved approvals leave the inbox.
        $this->assertCount(0, $this->service()->pendingFor($editor));
    }

    public function test_pending_for_includes_role_steps_for_role_holders(): void
    {
        $role = Role::where('slug', 'editor')->firstOrFail();

        $this->service()->request(
            ApproverSpec::role($role->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertCount(1, $this->service()->pendingFor($this->user('editor@flowsync.test')));
    }

    // ----------------------------------------------------------------- audit

    public function test_every_transition_writes_an_audit_row(): void
    {
        $approver = $this->user('editor@flowsync.test');
        $requester = $this->user('admin@flowsync.test');

        $approval = $this->service()->request(
            [ApproverSpec::user($approver->id), ApproverSpec::user($this->user('viewer@flowsync.test')->id)],
            $this->subject(),
            requester: $requester,
        );
        $approval = $this->service()->approve($approval, $approver);
        $this->service()->reject($approval, $this->user('viewer@flowsync.test'));

        $actions = HrmsAuditLog::forSubject($approval)
            // The scope orders newest-first for display; reorder so the
            // assertion reads in the order the transitions happened.
            ->reorder('id')
            ->pluck('action')
            ->all();

        $this->assertSame([
            'approval.requested',
            'approval.approved',
            'approval.rejected',
        ], $actions);

        $this->assertSame(3, HrmsAuditLog::forSubject($approval)->count());
    }

    public function test_an_auto_approval_is_audited(): void
    {
        $approval = $this->service()->request(
            [new ApproverSpec(type: ApproverType::Role)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertDatabaseHas('hrms_audit_logs', [
            'subject_type' => $approval->getMorphClass(),
            'subject_id' => $approval->id,
            'action' => 'approval.auto_approved',
        ]);
    }

    public function test_the_approval_steps_are_ordered_and_dense(): void
    {
        $approval = $this->service()->request(
            [ApproverSpec::user($this->user('editor@flowsync.test')->id), ApproverSpec::user($this->user('viewer@flowsync.test')->id)],
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertSame([1, 2], $approval->steps->pluck('step_order')->all());
        $this->assertSame(
            [1, 2],
            ApprovalStep::where('approval_id', $approval->id)->orderBy('step_order')->pluck('step_order')->all(),
        );
    }

    public function test_approvals_are_polymorphic_over_their_subject(): void
    {
        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        $this->assertInstanceOf(Workspace::class, $approval->approvable);
        $this->assertSame('approval-subject', $approval->approvable->slug);
    }

    public function test_deleting_an_approval_cascades_to_its_steps(): void
    {
        $approval = $this->service()->request(
            ApproverSpec::user($this->user('editor@flowsync.test')->id),
            $this->subject(),
            requester: $this->user('admin@flowsync.test'),
        );

        Approval::whereKey($approval->id)->forceDelete();

        $this->assertSame(0, ApprovalStep::where('approval_id', $approval->id)->count());
    }
}
