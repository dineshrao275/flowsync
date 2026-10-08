<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Survey\EngagementService;
use App\Services\Hrms\Survey\SurveyTemplateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P16.2 — surveys from scheduling to aggregates.
 *
 * One submission per campaign (identified and fingerprinted alike),
 * threshold-gated results with anonymous free text redacted to counts,
 * correct scale/NPS math, and invites that reach the audience without
 * toasting the inviter.
 */
class HrmsEngagementTest extends TestCase
{
    use IsolatesDatabase;

    public function test_open_invites_and_answers_land_once(): void
    {
        $owner = $this->makeEmployee(withUser: true);
        $hr = $this->makeUser();
        $services = $this->services();

        $template = $services->templates->createTemplate(
            ['name' => 'Pulse', 'type' => 'pulse'],
            [['text' => 'How are you?', 'type' => 'scale', 'is_required' => true, 'min' => 1, 'max' => 5]],
            $hr,
        );
        $campaign = $services->engagement->schedule($template, ['name' => 'Q3 Pulse'], $hr);
        $services->engagement->open($campaign->refresh(), $hr);

        $this->assertTrue(UserNotification::query()
            ->where('user_id', $owner->user_id)
            ->where('type', 'hrms.survey.invited')
            ->exists());
        $this->assertFalse(UserNotification::query()
            ->where('user_id', $hr->id)
            ->where('type', 'hrms.survey.invited')
            ->exists());

        $question = $template->questions()->firstOrFail();

        $services->engagement->respond($campaign->refresh(), $owner, [
            ['question_id' => $question->id, 'value' => 4],
        ], [], $owner->user);

        try {
            $services->engagement->respond($campaign->refresh(), $owner, [
                ['question_id' => $question->id, 'value' => 5],
            ]);
            $this->fail('A second submission landed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_anonymous_answers_fingerprint_and_redact(): void
    {
        $services = $this->services();

        $template = $services->templates->createTemplate(
            ['name' => 'Honest Box', 'type' => 'pulse', 'is_anonymous' => true],
            [['text' => 'Say it.', 'type' => 'text', 'is_required' => true]],
        );
        $campaign = $services->engagement->schedule($template, [
            'name' => 'Q3 Honest',
            'anonymity_threshold' => 1,
        ]);
        $services->engagement->open($campaign->refresh());

        $meta = ['ip' => '10.0.0.1', 'user_agent' => 'TestAgent'];
        $question = $template->questions()->firstOrFail();

        $first = $services->engagement->respond($campaign->refresh(), null, [
            ['question_id' => $question->id, 'value' => 'More snacks.'],
        ], $meta);

        $this->assertNull($first->employee_id);
        $this->assertNotNull($first->respondent_key);

        try {
            $services->engagement->respond($campaign->refresh(), null, [
                ['question_id' => $question->id, 'value' => 'More cake.'],
            ], $meta);
            $this->fail('A second fingerprint landed.');
        } catch (ValidationException) {
        }

        $result = $services->engagement->results($campaign->refresh());

        $this->assertSame(['responses' => 1], $result['results'][0]['aggregates']);
    }

    public function test_results_below_threshold_are_empty_not_errors(): void
    {
        $services = $this->services();

        $template = $services->templates->createTemplate(
            ['name' => 'Mood', 'type' => 'pulse'],
            [['text' => 'Mood?', 'type' => 'scale', 'min' => 1, 'max' => 5]],
        );
        $campaign = $services->engagement->schedule($template, ['name' => 'Q3 Mood']);
        $services->engagement->open($campaign->refresh());

        $owner = $this->makeEmployee(withUser: true);
        $question = $template->questions()->firstOrFail();
        $services->engagement->respond($campaign->refresh(), $owner, [
            ['question_id' => $question->id, 'value' => 3],
        ]);

        $quiet = $services->engagement->results($campaign->refresh());

        $this->assertSame([], $quiet['results']);
        $this->assertSame(1, $quiet['response_count']);

        $campaign->update(['anonymity_threshold' => 1]);
        Cache::forget("hrms.survey.results.{$campaign->id}");

        $loud = $services->engagement->results($campaign->refresh());

        $this->assertSame(3.0, $loud['results'][0]['aggregates']['average']);
    }

    public function test_nps_math_counts_bands_not_means(): void
    {
        $services = $this->services();

        $template = $services->templates->createTemplate(
            ['name' => 'NPS', 'type' => 'engagement'],
            [['text' => 'Recommend?', 'type' => 'nps', 'min' => 0, 'max' => 10]],
        );
        $campaign = $services->engagement->schedule($template, [
            'name' => 'Q3 NPS',
            'anonymity_threshold' => 1,
        ]);
        $services->engagement->open($campaign->refresh());

        $question = $template->questions()->firstOrFail();

        foreach ([10, 9, 7, 5, 0] as $index => $score) {
            $services->engagement->respond($campaign->refresh(), $this->makeEmployee(), [
                ['question_id' => $question->id, 'value' => $score],
            ]);
        }

        // A mean would say 6.2/10 ("fine"); the bands say 2 lovers, 2
        // detractors, score 0 — which is the truth the method exists for.
        $result = $services->engagement->results($campaign->refresh());

        $this->assertSame([
            'promoters' => 2, 'passives' => 1, 'detractors' => 2, 'score' => 0,
        ], $result['results'][0]['aggregates']);
    }

    public function test_closed_campaigns_stop_answering(): void
    {
        $services = $this->services();

        $template = $services->templates->createTemplate(['name' => 'Done'], []);
        $campaign = $services->engagement->schedule($template, ['name' => 'Q3 Done']);
        $services->engagement->open($campaign->refresh());
        $services->engagement->close($campaign->refresh());

        try {
            $services->engagement->respond($campaign->refresh(), $this->makeEmployee(), []);
            $this->fail('A closed campaign answered.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return object{templates: SurveyTemplateService, engagement: EngagementService}
     */
    private function services(): object
    {
        return (object) ['templates' => app(SurveyTemplateService::class), 'engagement' => app(EngagementService::class)];
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return User::create([
            'name' => "Survey User {$sequence}",
            'email' => "survey.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => 'EMP-SURV-'.$sequence,
            'name' => "Survey Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $withUser ? $this->makeUser()->id : null,
        ]);
    }
}
