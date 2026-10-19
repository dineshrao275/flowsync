<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\ExitInterview;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\User;
use App\Services\Hrms\Lifecycle\ExitInterviewService;
use App\Services\Hrms\OffboardingService;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F6.6 — exit interview: the leaver's own form, HR access, and anonymity-guarded insights. */
class HrmsExitInterviewTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $leaverUser;

    private Employee $leaver;

    private OffboardingCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.offboarding']);

        $this->leaverUser = $this->userWith(['hrms.view']);
        $this->leaver = $this->makeEmployee('Leaver', ['user_id' => $this->leaverUser->id]);
        $this->case = app(OffboardingService::class)->initiate($this->leaver, now()->addDays(14), 'resigned');
    }

    private function answers(array $over = []): array
    {
        return $over + ['primary_reason' => 'career_growth', 'manager_support' => 4, 'would_return' => 'maybe', 'went_well' => 'The team.'];
    }

    public function test_the_leaver_sees_the_questions_and_submits_their_own_answers(): void
    {
        $this->actAs($this->leaverUser);

        $mine = $this->getJson('/api/hrms/exit-interview')->assertOk()->json();
        $this->assertSame('pending', $mine['interview']['status']);
        $this->assertContains('primary_reason', array_column($mine['questions'], 'key'));

        $this->putJson('/api/hrms/exit-interview', ['answers' => $this->answers()])->assertOk()->assertJsonPath('interview.status', 'completed');
        $this->assertSame(4, ExitInterview::firstOrFail()->answers['manager_support']);
    }

    public function test_required_questions_and_option_values_are_enforced(): void
    {
        $this->actAs($this->leaverUser);

        $this->putJson('/api/hrms/exit-interview', ['answers' => ['manager_support' => 4]])->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.primary_reason', 'answers.would_return']);
        $this->putJson('/api/hrms/exit-interview', ['answers' => $this->answers(['manager_support' => 9])])->assertUnprocessable()->assertJsonValidationErrors('answers.manager_support');
        $this->putJson('/api/hrms/exit-interview', ['answers' => $this->answers(['primary_reason' => 'aliens'])])->assertUnprocessable();
    }

    public function test_someone_with_no_exit_in_progress_has_nothing_to_fill_in(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/exit-interview')->assertOk()->assertJsonPath('interview', null);
        $this->putJson('/api/hrms/exit-interview', ['answers' => $this->answers()])->assertNotFound();
    }

    public function test_hr_reads_records_and_waives_while_others_cannot(): void
    {
        $hr = $this->userWith(['hrms.view', 'hrms.offboarding.view', 'hrms.offboarding.manage']);
        $url = "/api/hrms/offboarding/cases/{$this->case->id}/exit-interview";

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson($url)->assertForbidden();

        $this->actAs($hr);
        $this->getJson($url)->assertOk()->assertJsonPath('interview.status', 'pending');
        $this->putJson($url, ['answers' => $this->answers()])->assertOk()->assertJsonPath('interview.status', 'completed');
        $this->postJson("$url/waive")->assertUnprocessable(); // already completed
    }

    public function test_a_waived_interview_stays_waived(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.offboarding.view', 'hrms.offboarding.manage']));
        $url = "/api/hrms/offboarding/cases/{$this->case->id}/exit-interview";

        $this->postJson("$url/waive")->assertOk()->assertJsonPath('interview.status', 'waived');
        $this->putJson($url, ['answers' => $this->answers()])->assertUnprocessable();
    }

    public function test_insights_stay_hidden_until_enough_people_have_answered(): void
    {
        $service = app(ExitInterviewService::class);
        config(['hrms.exit_interview.min_group' => 3]);

        foreach ([['a', 'manager', 2], ['b', 'manager', 4]] as [$name, $reason, $score]) {
            $employee = $this->makeEmployee("Gone $name");
            $case = app(OffboardingService::class)->initiate($employee, now()->addDays(10), 'resigned');
            $service->submit($service->forCase($case), $this->answers(['primary_reason' => $reason, 'manager_support' => $score]), $this->userWith(['hrms.view']));
        }

        $this->actAs($this->userWith(['hrms.view', 'hrms.offboarding.view']));
        $this->getJson('/api/hrms/offboarding/exit-insights')->assertOk()->assertJsonPath('insights.enough', false)->assertJsonPath('insights.questions', []);

        $third = $this->makeEmployee('Gone c');
        $case = app(OffboardingService::class)->initiate($third, now()->addDays(10), 'resigned');
        $service->submit($service->forCase($case), $this->answers(['primary_reason' => 'workload', 'manager_support' => 3]), $this->userWith(['hrms.view']));

        $insights = $this->getJson('/api/hrms/offboarding/exit-insights')->assertOk()->json('insights');
        $this->assertTrue($insights['enough']);
        $this->assertSame(['manager' => 2, 'workload' => 1], $insights['questions']['primary_reason']['counts']);
        $this->assertEquals(3.0, $insights['questions']['manager_support']['average']);
        // Free text is counted but never aggregated or returned.
        $this->assertArrayNotHasKey('counts', $insights['questions']['went_well']);
        $this->assertStringNotContainsString('The team.', json_encode($insights));
    }
}
