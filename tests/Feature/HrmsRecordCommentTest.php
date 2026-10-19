<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Expense\ExpenseService;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F2.6 — a shared comment thread on HR records, governed by the record's own policy. */
class HrmsRecordCommentTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private Employee $owner;

    private User $ownerUser;

    private int $claimId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.expenses']);
        $this->ownerUser = $this->userWith(['hrms.view', 'hrms.expenses.view_own']);
        $this->owner = $this->makeEmployee('Olive Owner', ['user_id' => $this->ownerUser->id]);
        $this->claimId = app(ExpenseService::class)->create($this->owner, [
            'claim_date' => now()->toDateString(), 'period_year' => (int) now()->format('Y'), 'period_month' => (int) now()->format('n'), 'purpose' => 'Client lunch',
        ], [['description' => 'Lunch', 'amount' => '40']])->id;
    }

    public function test_the_owner_and_an_hr_reader_can_converse_and_a_stranger_cannot(): void
    {
        $this->actAs($this->ownerUser);
        $this->postJson("/api/hrms/records/expense/{$this->claimId}/comments", ['body' => 'Receipt attached.'])->assertCreated();

        $hr = $this->userWith(['hrms.view', 'hrms.expenses.view_all']);
        $this->actAs($hr);
        $this->postJson("/api/hrms/records/expense/{$this->claimId}/comments", ['body' => 'Thanks, checking.'])->assertCreated();
        $this->getJson("/api/hrms/records/expense/{$this->claimId}/comments")->assertOk()->assertJsonCount(2, 'comments')->assertJsonPath('comments.0.body', 'Receipt attached.');

        $stranger = $this->userWith(['hrms.view', 'hrms.expenses.view_own']);
        $this->makeEmployee('Sam Stranger', ['user_id' => $stranger->id]);
        $this->actAs($stranger);
        $this->getJson("/api/hrms/records/expense/{$this->claimId}/comments")->assertForbidden();
        $this->postJson("/api/hrms/records/expense/{$this->claimId}/comments", ['body' => 'hi'])->assertForbidden();
    }

    public function test_unknown_record_types_are_404(): void
    {
        $this->actAs($this->ownerUser);
        $this->getJson('/api/hrms/records/payslip/1/comments')->assertNotFound();
    }
}
