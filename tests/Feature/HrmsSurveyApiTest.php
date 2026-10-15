<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P16.3a — templates, campaigns and answers over HTTP.
 *
 * Reads ride view, mutations need manage, answers need invitation alone;
 * double submissions refuse, thresholds empty the results without
 * erroring, and the schedule command opens what started and closes what
 * ended. The self-service pair carries questions but never aggregates.
 */
class HrmsSurveyApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_viewer_reads_but_writes_nothing(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view', 'hrms.engagement.manage']));

        $template = $this->postJson('/api/hrms/engagement/templates', ['name' => 'Viewer Probe'])
            ->assertCreated()->json('template');

        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view']));

        $this->getJson('/api/hrms/engagement/templates')->assertOk();
        $this->getJson('/api/hrms/engagement/campaigns')->assertOk();

        $this->postJson('/api/hrms/engagement/templates', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/hrms/engagement/campaigns', [
            'template_id' => $template['id'],
            'name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_templates_build_with_questions_and_version_them(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view', 'hrms.engagement.manage']));

        $template = $this->postJson('/api/hrms/engagement/templates', [
            'name' => 'Pulse',
            'type' => 'pulse',
            'questions' => [
                ['text' => 'How are you?', 'type' => 'scale', 'is_required' => true, 'min' => 1, 'max' => 5],
                ['text' => 'Why?', 'type' => 'text'],
            ],
        ])->assertCreated()->json('template');

        $this->assertCount(2, $template['questions']);

        $synced = $this->putJson("/api/hrms/engagement/templates/{$template['id']}/questions", [
            'questions' => [['text' => 'How are you, really?', 'type' => 'scale', 'min' => 1, 'max' => 5]],
        ])->assertOk()->json('template');

        $this->assertCount(1, $synced['questions']);
        $this->assertSame('How are you, really?', $synced['questions'][0]['text']);
    }

    public function test_a_campaign_walks_schedule_to_results(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view', 'hrms.engagement.manage']));

        $template = $this->postJson('/api/hrms/engagement/templates', [
            'name' => 'Mood',
            'questions' => [['text' => 'Mood?', 'type' => 'scale', 'is_required' => true, 'min' => 1, 'max' => 5]],
        ])->assertCreated()->json('template');
        $question = $template['questions'][0];

        $campaign = $this->postJson('/api/hrms/engagement/campaigns', [
            'template_id' => $template['id'],
            'name' => 'Q3 Mood',
            'anonymity_threshold' => 1,
        ])->assertCreated()->json('campaign');
        $this->assertSame('scheduled', $campaign['status']);

        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/open", [])->assertOk();
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $employee->user_id)
            ->where('type', 'hrms.survey.invited')
            ->exists());

        // Foreign questions are refused, not silently dropped.
        $this->actAs($this->userWith(['hrms.view'], $employee));
        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/respond", [
            'answers' => [['question_id' => $question['id'] + 999999, 'value' => 3]],
        ])->assertStatus(422);

        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/respond", [
            'answers' => [['question_id' => $question['id'], 'value' => 4]],
        ])->assertCreated();

        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/respond", [
            'answers' => [['question_id' => $question['id'], 'value' => 5]],
        ])->assertStatus(422);

        $results = $this->getJson("/api/hrms/engagement/campaigns/{$campaign['id']}/results")->assertOk()->json();
        $this->assertEquals(4, $results['results'][0]['aggregates']['average']);
    }

    public function test_answers_need_invitation_alone(): void
    {
        $invited = $this->makeEmployee(withUser: true);
        $stranger = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view', 'hrms.engagement.manage']));

        $template = $this->postJson('/api/hrms/engagement/templates', [
            'name' => 'Explicit',
            'audience_scope' => 'explicit',
            'audience_meta' => ['employee_ids' => [$invited->id]],
            'questions' => [['text' => 'Mood?', 'type' => 'scale', 'min' => 1, 'max' => 5]],
        ])->assertCreated()->json('template');

        $campaign = $this->postJson('/api/hrms/engagement/campaigns', [
            'template_id' => $template['id'],
            'name' => 'Q3 Explicit',
        ])->assertCreated()->json('campaign');
        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/open", [])->assertOk();

        $question = $template['questions'][0];

        $this->actAs($this->userWith(['hrms.view'], $stranger));
        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/respond", [
            'answers' => [['question_id' => $question['id'], 'value' => 3]],
        ])->assertForbidden();
        $this->getJson("/api/hrms/engagement/campaigns/{$campaign['id']}/results")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view'], $invited));
        $this->postJson("/api/hrms/engagement/campaigns/{$campaign['id']}/respond", [
            'answers' => [['question_id' => $question['id'], 'value' => 3]],
        ])->assertCreated();

        $mine = $this->getJson('/api/hrms/engagement/my')->assertOk()->json('campaigns');
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['answered']);

        $form = $this->getJson("/api/hrms/engagement/my/{$campaign['id']}")->assertOk()->json();
        $this->assertCount(1, $form['questions']);
        $this->assertArrayNotHasKey('results', $form);
    }

    public function test_the_schedule_command_opens_closes_and_nudges(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.engagement.view', 'hrms.engagement.manage']));

        $template = $this->postJson('/api/hrms/engagement/templates', [
            'name' => 'Scheduled',
            'questions' => [['text' => 'Mood?', 'type' => 'scale', 'min' => 1, 'max' => 5]],
        ])->assertCreated()->json('template');

        $past = $this->postJson('/api/hrms/engagement/campaigns', [
            'template_id' => $template['id'],
            'name' => 'Past Window',
            'starts_at' => today()->subDays(10)->toDateString(),
            'ends_at' => today()->subDays(1)->toDateString(),
        ])->assertCreated()->json('campaign');

        $closing = $this->postJson('/api/hrms/engagement/campaigns', [
            'template_id' => $template['id'],
            'name' => 'Closing Soon',
            'starts_at' => today()->subDays(10)->toDateString(),
            'ends_at' => today()->addDays(2)->toDateString(),
        ])->assertCreated()->json('campaign');

        $this->artisan('hrms:surveys-open-close', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();

        // Started opens, ended closes, closing nudges its audience once.
        $this->assertSame('closed', $this->getJson("/api/hrms/engagement/campaigns/{$past['id']}")->assertOk()->json('campaign.status'));
        $this->assertSame('open', $this->getJson("/api/hrms/engagement/campaigns/{$closing['id']}")->assertOk()->json('campaign.status'));
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $employee->user_id)
            ->where('type', 'hrms.survey.closing_soon')
            ->exists());

        $this->artisan('hrms:surveys-open-close', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $employee->user_id)
            ->where('type', 'hrms.survey.closing_soon')
            ->count());
    }

    // ------------------------------------------------------------ helpers

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Survey Api Owner {$sequence}",
            'email' => "survey.api.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-SUA-'.$sequence,
            'name' => "Survey Api Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $userId,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Survey Api User {$sequence}",
                'email' => "survey.api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Survey Api Role {$sequence}",
            'slug' => "survey-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
