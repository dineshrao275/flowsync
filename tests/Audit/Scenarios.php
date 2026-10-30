<?php

declare(strict_types=1);

namespace Tests\Audit;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow scenarios for forms the sweep cannot reach by itself: a performance cycle that has to be at a later
 * stage, a sprint that must be active, a request only its assigned approver may decide, a document only the named
 * signer may sign. Each scenario builds the needed state by submitting the application's OWN forms, in order, as the
 * person the workflow designates, then submits the target form. Every step is recorded as a normal result.
 */
final class Scenarios
{
    /** @return array<string, Closure(FormAuditTest, AuditWorld): void> */
    public static function all(): array
    {
        return [
            'performance-cycle-stages' => function (FormAuditTest $t) {
                $n = 'performance-cycle-stages';
                $t->step($n, 'admin', 'POST', 'api/hrms/performance/cycles', [
                    'name' => 'Audit Stages Cycle', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31',
                ]);
                $cycle = self::latest('performance_cycles');
                foreach (['open-check-in', 'open-self-review', 'open-manager-review', 'open-calibration', 'complete'] as $stage) {
                    $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/{$stage}");
                }
            },

            'performance-reviews-and-feedback' => function (FormAuditTest $t) {
                $n = 'performance-reviews-and-feedback';
                $cycle = self::latest('performance_cycles');
                $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/open-check-in");
                $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/open-self-review");

                $employee = (int) DB::table('employees')->where('user_id', self::world()->users['employee'])->value('id');
                $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/reviews", ['employee_id' => $employee]);
                $review = self::latest('review_summaries', ['cycle_id' => $cycle]) ?? self::latest('review_summaries');
                if ($review === null) {
                    return;
                }
                $t->step($n, 'admin', 'PUT', "api/hrms/performance/reviews/{$review}", ['strengths' => 'Audit strengths', 'improvements' => 'Audit improvements']);
                $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/open-manager-review");
                // Opening manager review is what generates the 360 feedback requests.
                $feedback = self::latest('feedback_requests', ['status' => 'pending']);
                if ($feedback !== null) {
                    $respondent = self::userOfEmployee((int) DB::table('feedback_requests')->where('id', $feedback)->value('from_employee_id'));
                    $t->step($n, 'user:'.$respondent, 'POST', "api/hrms/performance/feedback-requests/{$feedback}/respond", ['action' => 'submit', 'rating' => 4, 'body' => 'Audit feedback']);
                }
                $t->step($n, 'admin', 'POST', "api/hrms/performance/cycles/{$cycle}/open-calibration");
                $t->step($n, 'admin', 'POST', "api/hrms/talent/reviews/{$review}/calibrate");
                $t->step($n, 'admin', 'POST', "api/hrms/talent/reviews/{$review}/finalize");
                $managerEmployee = DB::table('review_summaries')->where('id', $review)->value('manager_employee_id')
                    ?? DB::table('employees')->where('id', DB::table('review_summaries')->where('id', $review)->value('employee_id'))->value('manager_id');
                $t->step($n, 'user:'.self::userOfEmployee((int) $managerEmployee), 'POST', "api/hrms/performance/reviews/{$review}/acknowledge");

                $goal = self::latest('performance_goals');
                $task = (int) DB::table('tasks')->whereNull('parent_id')->orderBy('id')->value('id');
                if ($goal !== null && $task) {
                    $t->step($n, 'admin', 'POST', "api/hrms/performance/goals/{$goal}/tasks", ['task_id' => $task]);
                    $t->step($n, 'admin', 'DELETE', "api/hrms/performance/goals/{$goal}/tasks/{$task}");
                }
            },

            'sprints' => function (FormAuditTest $t) {
                $n = 'sprints';
                $project = (int) (DB::table('sprints')->value('project_id') ?? self::latest('projects'));
                // An active sprint blocks starting another: finish it first (also exercises the complete form).
                $active = DB::table('sprints')->where('project_id', $project)->where('status', 'active')->value('id');
                if ($active !== null) {
                    $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints/{$active}/complete");
                }
                $planned = DB::table('sprints')->where('project_id', $project)->where('status', 'planned')->orderByDesc('id')->value('id');
                if ($planned === null) {
                    $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints", ['name' => 'Audit sprint']);
                    $planned = self::latest('sprints');
                }
                $task = (int) DB::table('tasks')->where('project_id', $project)->whereNull('parent_id')->orderBy('id')->value('id');
                $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints/{$planned}/tasks", ['task_ids' => [$task]]);
                $t->step($n, 'admin', 'DELETE', "api/projects/{$project}/sprints/{$planned}/tasks/{$task}");
                $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints/{$planned}/tasks", ['task_ids' => [$task]]);
                $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints/{$planned}/start");
                $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints", ['name' => 'Audit carry-over sprint']);
                $carry = self::latest('sprints');
                $t->step($n, 'admin', 'POST', "api/projects/{$project}/sprints/{$planned}/complete", ['target_sprint_id' => $carry]);
            },

            'comp-off' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'comp-off';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                foreach (range(1, 3) as $i) {
                    $t->step($n, 'admin', 'POST', 'api/hrms/comp-off/credits', ['employee_id' => $employee, 'work_date' => '2026-10-1'.$i, 'minutes' => 1000, 'source' => 'manual']);
                }
                $dates = [['2026-12-14', '2026-12-14'], ['2026-12-15', '2026-12-15'], ['2026-12-16', '2026-12-16'], ['2026-12-17', '2026-12-17']];
                $ids = [];
                foreach ($dates as [$from, $to]) {
                    $t->step($n, 'employee', 'POST', 'api/hrms/comp-off/requests', ['from_date' => $from, 'to_date' => $to, 'reason' => 'Audit comp-off']);
                    $ids[] = self::latest('comp_off_requests');
                }
                [$approve, $reject, $cancel, $delete] = $ids;
                $t->step($n, self::approver('comp_off_requests', $approve), 'POST', "api/hrms/comp-off/requests/{$approve}/approve");
                $t->step($n, self::approver('comp_off_requests', $reject), 'POST', "api/hrms/comp-off/requests/{$reject}/reject", ['note' => 'Audit rejection']);
                $t->step($n, 'employee', 'POST', "api/hrms/comp-off/requests/{$cancel}/cancel");
                $t->step($n, 'employee', 'DELETE', "api/hrms/comp-off/requests/{$delete}");
            },

            'employee-changes' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'employee-changes';
                $ids = [];
                foreach (['employee', 'auditor', 'finance_admin'] as $i => $who) {
                    $target = (int) DB::table('employees')->where('user_id', $w->users[$who])->value('id');
                    $t->step($n, 'hr_manager', 'POST', "api/hrms/employees/{$target}/changes", ['type' => 'confirmation', 'effective_date' => '2026-12-0'.($i + 1), 'reason' => 'Audit confirmation']);
                    $ids[] = self::latest('employee_changes');
                }
                [$approve, $reject, $withdraw] = $ids;
                $t->step($n, self::approver('employee_changes', $approve), 'POST', "api/hrms/employee-changes/{$approve}/approve");
                $t->step($n, self::approver('employee_changes', $reject), 'POST', "api/hrms/employee-changes/{$reject}/reject", ['note' => 'Audit rejection']);
                $t->step($n, 'hr_manager', 'POST', "api/hrms/employee-changes/{$withdraw}/withdraw");
            },

            'improvement-plans' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'improvement-plans';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $t->step($n, 'admin', 'POST', "api/hrms/employees/{$employee}/improvement-plans", [
                    'reason' => 'Audit plan', 'start_date' => '2026-10-26', 'end_date' => '2026-12-31',
                    'milestones' => [['title' => 'First milestone', 'due_date' => '2026-11-30']],
                ]);
                $plan = self::latest('improvement_plans');
                $milestone = (string) (json_decode((string) DB::table('improvement_plans')->where('id', $plan)->value('milestones'), true)[0]['id'] ?? '1');
                $t->step($n, 'admin', 'PUT', "api/hrms/improvement-plans/{$plan}/milestones/{$milestone}", ['done' => true]);
                $t->step($n, 'admin', 'POST', "api/hrms/improvement-plans/{$plan}/extend", ['end_date' => '2027-03-31']);
                $t->step($n, 'admin', 'POST', "api/hrms/improvement-plans/{$plan}/close", ['status' => 'completed', 'outcome' => 'Audit outcome']);
            },

            'loans' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'loans';
                $ids = [];
                foreach (['employee' => 'employee', 'finance_admin' => 'finance_admin'] as $who => $perspective) {
                    $employee = (int) DB::table('employees')->where('user_id', $w->users[$who])->value('id');
                    $t->step($n, $perspective, 'POST', 'api/hrms/loans', ['employee_id' => $employee, 'kind' => 'loan', 'principal' => 12000, 'installments' => 6, 'first_year' => 2027, 'first_month' => 1, 'reason' => 'Audit loan']);
                    $ids[] = self::latest('employee_loans');
                }
                [$stop, $reject] = $ids;
                // The chain is manager then HR: each leg is decided by whoever the engine names at that moment.
                $t->step($n, self::approver('employee_loans', $stop), 'POST', "api/hrms/loans/{$stop}/approve");
                $t->step($n, self::approver('employee_loans', $stop), 'POST', "api/hrms/loans/{$stop}/approve");
                $t->step($n, 'admin', 'POST', "api/hrms/loans/{$stop}/stop");
                $t->step($n, self::approver('employee_loans', $reject), 'POST', "api/hrms/loans/{$reject}/reject");
            },

            'statutory-declarations' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'statutory-declarations';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $ids = [];
                foreach (['80C', '80D'] as $section) {
                    $t->step($n, 'employee', 'POST', 'api/hrms/payroll/statutory/declarations', ['employee_id' => $employee, 'fiscal_year' => 2026, 'section' => $section, 'declared_amount' => 50000]);
                    $ids[] = self::latest('statutory_declarations');
                }
                [$verify, $reject] = $ids;
                foreach ($ids as $id) {
                    $t->step($n, 'employee', 'POST', "api/hrms/payroll/statutory/declarations/{$id}/submit");
                }
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/statutory/declarations/{$verify}/verify");
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/statutory/declarations/{$reject}/reject", ['note' => 'Audit rejection']);
            },

            'salary-revisions' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'salary-revisions';
                // Someone with no manager: the manager step has nobody to wait for, so the chain resolves itself.
                $employee = (int) DB::table('employees')->whereNull('manager_id')->whereNotNull('user_id')->whereIn('id', DB::table('employee_salary_structures')->pluck('employee_id'))->orderBy('id')->value('id');
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/employees/{$employee}/revisions", ['to_ctc' => 5000000, 'effective_from' => '2026-12-01', 'reason' => 'Audit revision']);
                $revision = self::latest('salary_revisions');
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/employees/{$employee}/revisions/{$revision}/apply");

                // Evidence for the finding in docs/qa: someone WITH a manager waits on a manager step no route can resolve.
                $managed = (int) DB::table('employees')->whereNotNull('manager_id')->whereNotNull('user_id')->whereIn('id', DB::table('employee_salary_structures')->pluck('employee_id'))->orderBy('id')->value('id');
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/employees/{$managed}/revisions", ['to_ctc' => 9000000, 'effective_from' => '2026-12-01', 'reason' => 'Audit revision (has a manager)']);
                $t->step($n, 'payroll_manager', 'POST', "api/hrms/payroll/employees/{$managed}/revisions/".self::latest('salary_revisions').'/apply');
            },

            'e-signatures' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'e-signatures';
                // The database is restored between submissions but fake storage is not, so pick a document whose file survived.
                $document = null;
                foreach (DB::table('employee_documents')->orderByDesc('id')->get() as $doc) {
                    if (\Illuminate\Support\Facades\Storage::disk($doc->file_disk)->exists($doc->file_path)) {
                        $document = (int) $doc->id;
                        break;
                    }
                }
                if ($document === null) {
                    return;
                }
                $ids = [];
                foreach (['sign', 'decline'] as $i => $action) {
                    $employee = (int) DB::table('employees')->where('user_id', $w->users[$i === 0 ? 'employee' : 'manager'])->value('id');
                    $t->step($n, 'hr_manager', 'POST', 'api/hrms/signatures', ['document_id' => $document, 'employee_id' => $employee, 'message' => 'Please sign']);
                    $ids[$action] = [self::latest('document_signatures'), $employee];
                }
                [$signId, $signer] = $ids['sign'];
                $t->step($n, 'user:'.self::userOfEmployee($signer), 'POST', "api/hrms/signatures/{$signId}/sign", ['typed_name' => 'Audit Signer', 'password' => 'password']);
                [$declineId, $decliner] = $ids['decline'];
                $t->step($n, 'user:'.self::userOfEmployee($decliner), 'POST', "api/hrms/signatures/{$declineId}/decline", ['reason' => 'Audit decline']);
            },

            'engagement' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'engagement';
                $template = self::latest('survey_templates');
                if ($template === null) {
                    $t->step($n, 'admin', 'POST', 'api/hrms/engagement/templates', ['name' => 'Audit Survey', 'type' => 'pulse', 'questions' => [['text' => 'How is it going?', 'type' => 'scale']]]);
                    $template = self::latest('survey_templates');
                }
                $campaign = null;
                $t->step($n, 'admin', 'POST', 'api/hrms/engagement/campaigns', ['template_id' => $template, 'name' => 'Audit pulse', 'starts_at' => '2026-10-27', 'ends_at' => '2026-12-31']);
                $campaign = self::latest('survey_campaigns');
                $t->step($n, 'admin', 'POST', "api/hrms/engagement/campaigns/{$campaign}/open");
                $answers = self::answers((int) $template);
                $t->step($n, 'employee', 'POST', "api/hrms/engagement/campaigns/{$campaign}/respond", ['answers' => $answers]);
                $t->step($n, 'admin', 'POST', "api/hrms/engagement/campaigns/{$campaign}/close");
                // A template with no campaign behind it can be deleted (the sweep's fixture is one that has campaigns).
                $t->step($n, 'admin', 'POST', 'api/hrms/engagement/templates', ['name' => 'Audit disposable survey', 'type' => 'pulse', 'questions' => [['text' => 'How is it going?', 'type' => 'scale']]]);
                $t->step($n, 'admin', 'DELETE', 'api/hrms/engagement/templates/'.self::latest('survey_templates'));
            },

            'timesheets' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'timesheets';
                $task = (int) DB::table('tasks')->orderBy('id')->value('id');
                $projectId = (int) DB::table('tasks')->where('id', $task)->value('project_id');
                $t->step($n, 'admin', 'POST', "api/projects/{$projectId}/tasks/{$task}/work-logs", ['started_at' => '2026-10-26 09:00:00', 'ended_at' => '2026-10-26 11:00:00', 'description' => 'Audit work 1']);
                $t->step($n, 'admin', 'POST', 'api/time/timer/stop');
                $t->step($n, 'admin', 'POST', 'api/timesheets/submit', ['week' => '2026-10-26']);
                $sheet = self::latest('timesheets');
                if ($sheet !== null) {
                    $t->step($n, 'project_lead', 'POST', "api/timesheets/{$sheet}/approve");
                }
                $t->step($n, 'admin', 'POST', "api/projects/{$projectId}/tasks/{$task}/work-logs", ['started_at' => '2026-10-19 09:00:00', 'ended_at' => '2026-10-19 11:00:00', 'description' => 'Audit work 2']);
                $t->step($n, 'admin', 'POST', 'api/time/timer/stop');
                $t->step($n, 'admin', 'POST', 'api/timesheets/submit', ['week' => '2026-10-19']);
                $sheet2 = self::latest('timesheets');
                if ($sheet2 !== null) {
                    $t->step($n, 'project_lead', 'POST', "api/timesheets/{$sheet2}/reject", ['note' => 'Audit rejection']);
                }
            },

            'asset-handover' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'asset-handover';
                $asset = (int) DB::table('assets')->where('status', 'available')->orderByDesc('id')->value('id');
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $available = (int) DB::table('assets')->where('status', 'available')->where('id', '!=', $asset)->orderByDesc('id')->value('id');
                if ($available) {
                    $t->step($n, 'admin', 'POST', "api/hrms/assets/{$asset}/assign", ['employee_id' => $employee, 'condition_out' => 'good']);
                    $t->step($n, 'admin', 'POST', "api/hrms/assets/{$asset}/replace", [
                        'replacement_asset_id' => $available,
                        'reason' => 'damaged',
                        'assign_to_holder' => true,
                    ]);
                }
                $asset2 = (int) DB::table('assets')->where('status', 'available')->orderByDesc('id')->value('id');
                if ($asset2) {
                    $t->step($n, 'admin', 'POST', "api/hrms/assets/{$asset2}/assign", ['employee_id' => $employee, 'condition_out' => 'good']);
                    $t->step($n, 'admin', 'POST', "api/hrms/assets/{$asset2}/return", ['condition_in' => 'good']);
                }
            },

            'document-requests' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'document-requests';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $document = null;
                foreach (DB::table('employee_documents')->orderByDesc('id')->get() as $doc) {
                    if (\Illuminate\Support\Facades\Storage::disk($doc->file_disk)->exists($doc->file_path)) {
                        $document = $doc;
                        break;
                    }
                }
                if ($document === null) {
                    return;
                }
                $mine = (int) $document->employee_id;
                $t->step($n, 'hr_manager', 'POST', 'api/hrms/document-requests', ['employee_id' => $mine, 'document_type_id' => $document->document_type_id, 'title' => 'Audit request 1']);
                $req1 = self::latest('document_requests');
                $t->step($n, 'user:'.self::userOfEmployee($mine), 'POST', "api/hrms/document-requests/{$req1}/submit", ['document_id' => $document->id]);
                $t->step($n, 'hr_manager', 'POST', "api/hrms/document-requests/{$req1}/accept");

                $t->step($n, 'hr_manager', 'POST', 'api/hrms/document-requests', ['employee_id' => $mine, 'document_type_id' => $document->document_type_id, 'title' => 'Audit request 2']);
                $req2 = self::latest('document_requests');
                $t->step($n, 'user:'.self::userOfEmployee($mine), 'POST', "api/hrms/document-requests/{$req2}/submit", ['document_id' => $document->id]);
                $t->step($n, 'hr_manager', 'POST', "api/hrms/document-requests/{$req2}/reject", ['note' => 'Audit rejection']);
            },

            'case-task-sync' => function (FormAuditTest $t) {
                $n = 'case-task-sync';
                $case = self::latest('onboarding_cases');
                $task = DB::table('onboarding_case_tasks')->where('case_id', $case)->where('category', 'task')->orderBy('id')->value('id');
                if ($task === null) {
                    return;
                }
                $project = (int) DB::table('projects')->orderBy('id')->value('id');
                $t->step($n, 'admin', 'POST', "api/hrms/onboarding/cases/{$case}/tasks/{$task}/convert", ['project_id' => $project]);
                $t->step($n, 'admin', 'POST', "api/hrms/onboarding/cases/{$case}/tasks/{$task}/sync");
            },

            'expense-claims' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'expense-claims';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $t->step($n, 'employee', 'POST', 'api/hrms/expenses/claims', ['employee_id' => $employee]);
                $claim = self::latest('expense_claims');
                $t->step($n, 'employee', 'POST', "api/hrms/expenses/claims/{$claim}/submit");
                $t->step($n, 'finance_admin', 'POST', "api/hrms/expenses/claims/{$claim}/decide", ['verdict' => 'reject', 'reason' => 'Audit rejection']);
            },

            'two-factor' => function (FormAuditTest $t) {
                $n = 'two-factor';
                $setup = $t->step($n, 'admin', 'POST', 'api/auth/2fa/setup', ['password' => 'password']);
                $secret = (string) ($setup->json('secret') ?? '');
                if ($secret === '') {
                    return;
                }
                $t->step($n, 'admin', 'POST', 'api/auth/2fa/confirm', ['code' => \App\Support\Totp::code($secret), 'password' => 'password']);
                $codes = $t->step($n, 'admin', 'POST', 'api/auth/2fa/recovery-codes', ['password' => 'password', 'code' => \App\Support\Totp::code($secret, time() + 30)])->json('recovery_codes') ?? [];
                // A time code cannot be reused inside its window, so turning it off uses a recovery code.
                $t->step($n, 'admin', 'POST', 'api/auth/2fa/disable', ['password' => 'password', 'code' => (string) ($codes[0] ?? '')]);
            },

            'move-requests' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'move-requests';
                // A project member (not a workflow manager) asking to move work into a gated status.
                $project = (int) DB::table('project_members')->where('user_id', $w->users['editor'])->orderBy('id')->value('project_id');
                $done = (int) DB::table('task_statuses')->where('project_id', $project)->where('is_done', true)->orderBy('id')->value('id');
                DB::table('task_statuses')->where('id', $done)->update(['entry_rules' => json_encode(['approval'])]);
                $tasks = DB::table('tasks')->where('project_id', $project)->whereNull('parent_id')->where('status_id', '!=', $done)->orderBy('id')->limit(3)->pluck('id')->all();
                foreach ($tasks as $task) {
                    $t->step($n, 'editor', 'POST', "api/projects/{$project}/tasks/{$task}/move", ['status_id' => $done]);
                }
                $requests = DB::table('task_move_requests')->where('status', 'pending')->orderBy('id')->pluck('id')->all();
                if (count($requests) >= 3) {
                    $t->step($n, 'admin', 'POST', "api/projects/{$project}/move-requests/{$requests[0]}/approve");
                    $t->step($n, 'admin', 'POST', "api/projects/{$project}/move-requests/{$requests[1]}/reject", ['note' => 'Audit rejection']);
                    $t->step($n, 'editor', 'POST', "api/projects/{$project}/move-requests/{$requests[2]}/withdraw");
                }
            },

            'exit-interview' => function (FormAuditTest $t) {
                $n = 'exit-interview';
                $case = DB::table('offboarding_cases')->whereIn('status', ['open', 'in_progress', 'pending'])->orderByDesc('id')->first()
                    ?? DB::table('offboarding_cases')->orderByDesc('id')->first();
                if ($case === null) {
                    return;
                }
                $answers = ['primary_reason' => 'career_growth', 'manager_support' => 4, 'would_return' => 'maybe', 'went_well' => 'Audit answer'];
                $t->step($n, 'hr_manager', 'PUT', "api/hrms/offboarding/cases/{$case->id}/exit-interview", ['answers' => $answers]);
                $leaver = self::userOfEmployee((int) $case->employee_id);
                if ($leaver !== null) {
                    $t->step($n, 'user:'.$leaver, 'PUT', 'api/hrms/exit-interview', ['answers' => $answers]);
                }
            },

            'approval-chain' => function (FormAuditTest $t) {
                $domain = (string) array_key_first((array) config('approvals.domains'));
                $t->step('approval-chain', 'admin', 'PUT', "api/hrms/approvals/chains/{$domain}", ['steps' => [['type' => 'role', 'role_slug' => 'hr_manager']]]);
            },

            'regularizations' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'regularizations';
                $employee = (int) DB::table('employees')->where('user_id', $w->users['employee'])->value('id');
                $managerId = DB::table('employees')->where('id', $employee)->value('manager_id');
                $managerUser = self::userOfEmployee((int) $managerId);
                if ($managerUser === null) {
                    return;
                }
                $t->step($n, 'employee', 'POST', 'api/hrms/attendance/regularizations', [
                    'work_date' => '2026-10-25',
                    'requested_first_in_at' => '2026-10-25 09:00:00',
                    'reason' => 'Audit regularization 1',
                ]);
                $req1 = self::latest('attendance_regularization_requests');
                $t->step($n, 'user:'.$managerUser, 'POST', "api/hrms/attendance/regularizations/{$req1}/approve", ['note' => 'Approved by manager']);

                $t->step($n, 'employee', 'POST', 'api/hrms/attendance/regularizations', [
                    'work_date' => '2026-10-24',
                    'requested_first_in_at' => '2026-10-24 09:00:00',
                    'reason' => 'Audit regularization 2',
                ]);
                $req2 = self::latest('attendance_regularization_requests');
                $t->step($n, 'user:'.$managerUser, 'POST', "api/hrms/attendance/regularizations/{$req2}/reject", ['note' => 'Rejected by manager']);
            },

            'disposable-project' => function (FormAuditTest $t) {
                $n = 'disposable-project';
                $ws = (int) DB::table('workspaces')->orderBy('id')->value('id');
                $t->step($n, 'admin', 'POST', "api/workspaces/{$ws}/projects", ['name' => 'Disposable Project']);
                $disposable = self::latest('projects');
                if ($disposable !== null) {
                    $t->step($n, 'admin', 'DELETE', "api/projects/{$disposable}");
                }
            },

            'impersonation' => function (FormAuditTest $t, AuditWorld $w) {
                $n = 'impersonation';
                $t->step($n, 'super_admin', 'POST', 'api/impersonate', ['tenant_id' => $w->tenant->id, 'reason' => 'Audit test impersonation']);
                $t->step($n, 'super_admin', 'POST', 'api/impersonate/stop');
            },

            'payroll-lifecycle' => function (FormAuditTest $t) {
                $n = 'payroll-lifecycle';
                $t->step($n, 'admin', 'POST', 'api/hrms/payroll/runs', ['period_year' => 2026, 'period_month' => 11]);
                $run = self::latest('payroll_runs');
                if ($run === null) {
                    return;
                }
                $t->step($n, 'admin', 'POST', "api/hrms/payroll/runs/{$run}/calculate");
                $payslip = self::latest('payslips', ['run_id' => $run]);
                if ($payslip !== null) {
                    $t->step($n, 'admin', 'POST', "api/hrms/payroll/payslips/{$payslip}/adjustments", [
                        'kind' => 'earning', 'label' => 'Audit bonus', 'amount' => '1000',
                    ]);
                    $adjustment = self::latest('payslip_adjustments', ['payslip_id' => $payslip]);
                    $t->step($n, 'admin', 'POST', 'api/hrms/payroll/statutory/recompute', ['run_id' => $run]);
                    if ($adjustment !== null) {
                        $t->step($n, 'admin', 'DELETE', "api/hrms/payroll/payslips/{$payslip}/adjustments/{$adjustment}");
                    }
                }
                $t->step($n, 'admin', 'POST', "api/hrms/payroll/runs/{$run}/approve");
                $t->step($n, 'admin', 'POST', "api/hrms/payroll/runs/{$run}/publish");
                $t->step($n, 'admin', 'POST', "api/hrms/payroll/runs/{$run}/mark-paid");
            },

            'tenant-intake' => function (FormAuditTest $t) {
                $n = 'tenant-intake';
                $t->step($n, 'super_admin', 'POST', 'api/tenants', ['name' => 'Intake Audit Co', 'slug' => 'intake-audit-co']);
                $id = self::latest('tenants');
                if ($id === null) {
                    return;
                }
                $planId = DB::table('subscription_plans')->where('slug', 'pro')->value('id');
                $t->step($n, 'super_admin', 'PUT', "api/tenants/{$id}/intake", [
                    'industry' => 'Software', 'company_size' => '11-50', 'country' => 'in',
                    'billing_email' => 'billing@intakeaudit.test', 'contact_name' => 'Audit Contact',
                    'contact_email' => 'contact@intakeaudit.test', 'admin_name' => 'Audit Admin',
                    'admin_email' => 'admin@intakeaudit.test', 'plan_id' => $planId, 'start_trial' => true,
                ]);
                $t->step($n, 'super_admin', 'POST', "api/tenants/{$id}/intake/submit", [
                    'admin_password' => 'secret123', 'admin_password_confirmation' => 'secret123',
                ]);
            },

            'marketing-leads' => function (FormAuditTest $t) {
                $n = 'marketing-leads';
                $t->step($n, 'guest', 'POST', 'api/contact', [
                    'name' => 'Lead Tester', 'email' => 'lead.tester@example.com', 'message' => 'Hello audit',
                ]);
                $central = DB::connection(config('tenancy.system.connection'));
                $leadId = $central->table('marketing_leads')->where('email', 'lead.tester@example.com')->value('id');
                if ($leadId !== null) {
                    $t->step($n, 'super_admin', 'PUT', "api/system/leads/{$leadId}", ['status' => 'contacted']);
                    $t->step($n, 'super_admin', 'DELETE', "api/system/leads/{$leadId}");
                }
            },

            'system-queue' => function (FormAuditTest $t) {
                $n = 'system-queue';
                $central = DB::connection(config('tenancy.system.connection'));
                $jobId = $central->table('failed_jobs')->insertGetId([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'connection' => 'database',
                    'queue' => 'default',
                    'payload' => json_encode(['displayName' => 'App\\Jobs\\SampleJob']),
                    'exception' => "RuntimeException: test\n#0 /file.php(1)",
                    'failed_at' => now(),
                ]);
                $t->step($n, 'super_admin', 'POST', "api/system/queue/failed/{$jobId}/retry");
                $delId = $central->table('failed_jobs')->insertGetId([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'connection' => 'database',
                    'queue' => 'default',
                    'payload' => json_encode(['displayName' => 'App\\Jobs\\SampleJob']),
                    'exception' => "RuntimeException: test\n#0 /file.php(1)",
                    'failed_at' => now(),
                ]);
                $t->step($n, 'super_admin', 'DELETE', "api/system/queue/failed/{$delId}");
            },
        ];
    }

    private static ?AuditWorld $world = null;

    public static function bind(AuditWorld $world): void
    {
        self::$world = $world;
    }

    private static function world(): AuditWorld
    {
        return self::$world ?? throw new \RuntimeException('Scenarios not bound to a world.');
    }

    /** Newest record id of a table (optionally filtered); null when the table is empty. */
    private static function latest(string $table, array $where = []): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        $id = $query->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function userOfEmployee(int $employeeId): ?int
    {
        $id = DB::table('employees')->where('id', $employeeId)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /** `user:<id>` of whoever the workflow assigned the pending step of this record to, or the admin as a fallback. */
    private static function approver(string $table, ?int $id): string
    {
        $class = match ($table) {
            'comp_off_requests' => 'CompOffRequest',
            'employee_changes' => 'EmployeeChange',
            'employee_loans' => 'EmployeeLoan',
            'salary_revisions' => 'SalaryRevision',
            'expense_claims' => 'ExpenseClaim',
            default => '',
        };
        $approval = DB::table('approvals')->where('status', 'pending')->where('approvable_type', 'like', '%\\'.$class)->where('approvable_id', $id)->orderByDesc('id')->first();
        if ($approval !== null) {
            $step = DB::table('approval_steps')->where('approval_id', $approval->id)->where('status', 'pending')->orderBy('id')->first();
            $userId = $step?->approver_user_id
                ?: ($step?->approver_role_id ? DB::table('role_user')->where('role_id', $step->approver_role_id)->value('user_id') : null)
                ?: ($step?->approver_employee_id ? DB::table('employees')->where('id', $step->approver_employee_id)->value('user_id') : null)
                ?: ($approval->requested_by_employee_id
                    ? DB::table('employees as m')->join('employees as e', 'e.manager_id', '=', 'm.id')->where('e.id', $approval->requested_by_employee_id)->value('m.user_id')
                    : null);
            if ($userId) {
                return 'user:'.$userId;
            }
        }

        return 'admin';
    }

    /** One valid answer per question of a survey template. */
    private static function answers(int $template): array
    {
        $answers = [];
        foreach (DB::table('survey_questions')->where('template_id', $template)->orderBy('sequence')->get() as $q) {
            $answers[] = ['question_id' => (int) $q->id, 'value' => match ($q->type) {
                'nps' => 9,
                'yes_no' => 1,
                'multiple_choice' => [array_values((array) json_decode((string) $q->options, true))[0] ?? 'a'],
                'text' => 'Audit answer',
                default => 4,
            }];
        }

        return $answers;
    }
}
