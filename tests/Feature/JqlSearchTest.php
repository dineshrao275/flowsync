<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F3.2 — JQL-style search on the project task list: results, scoping, safety and plan gating. */
class JqlSearchTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['time_tracking', 'jql', 'custom_fields']);
        $this->loginAdmin();
        $this->adminId = (int) User::where('email', 'admin@flowsync.test')->value('id');

        $workspace = $this->postJson('/api/workspaces', ['name' => 'JQL WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'JQL Project', 'key' => 'JQ'])->assertCreated()->json('project.id');

        $this->task('Alpha login bug', ['assignee_id' => $this->adminId, 'due_date' => '2026-03-01']);
        $this->task('Beta search feature', ['due_date' => '2026-06-15']);
        $this->task('Gamma report', ['assignee_id' => $this->adminId]);
    }

    private function task(string $title, array $extra = []): void
    {
        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => $title] + $extra)->assertCreated();
    }

    /** @return list<string> titles matching the query, in order */
    private function search(string $jql): array
    {
        return collect($this->getJson("/api/projects/{$this->project}/tasks?view=list&jql=".rawurlencode($jql))->assertOk()->json('tasks'))
            ->pluck('title')->all();
    }

    public function test_text_and_people_conditions(): void
    {
        $this->assertSame(['Alpha login bug'], $this->search('title ~ "login"'));
        $this->assertEqualsCanonicalizing(['Alpha login bug', 'Gamma report'], $this->search('assignee = currentUser()'));
        $this->assertSame(['Beta search feature'], $this->search('assignee IS EMPTY'));
        $this->assertEqualsCanonicalizing(['Alpha login bug', 'Gamma report'], $this->search('assignee = "admin@flowsync.test"'));
    }

    public function test_boolean_logic_and_dates(): void
    {
        $this->assertEqualsCanonicalizing(['Alpha login bug', 'Beta search feature'], $this->search('title ~ alpha OR title ~ beta'));
        $this->assertSame(['Gamma report'], $this->search('(title ~ alpha OR title ~ gamma) AND NOT title ~ alpha'));
        $this->assertSame(['Alpha login bug'], $this->search('due < 2026-04-01'));
        $this->assertSame(['Beta search feature'], $this->search('due >= 2026-06-01 AND assignee IS EMPTY'));
        $this->assertSame(['Gamma report'], $this->search('due IS EMPTY'));
    }

    public function test_status_membership_and_negation(): void
    {
        $this->assertCount(3, $this->search('status = "To Do"'));
        $this->assertSame([], $this->search('status = Done'));
        $this->assertCount(3, $this->search('status IN ("To Do", Done)'));
        $this->assertCount(3, $this->search('status != Done'));
        $this->assertSame([], $this->search('status NOT IN ("To Do")'));
    }

    public function test_order_by_sorts_the_result(): void
    {
        $this->assertSame(['Alpha login bug', 'Beta search feature', 'Gamma report'], $this->search('ORDER BY title ASC'));
        $this->assertSame(['Gamma report', 'Beta search feature', 'Alpha login bug'], $this->search('ORDER BY title DESC'));
    }

    public function test_custom_fields_can_be_queried(): void
    {
        $this->postJson('/api/custom-fields', ['entity' => 'task', 'label' => 'Customer', 'type' => 'text'])->assertCreated();
        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => 'Acme work', 'custom_fields' => ['customer' => 'Acme']])->assertCreated();

        $this->assertSame(['Acme work'], $this->search('cf.customer ~ acme'));
    }

    public function test_injection_attempts_are_values_not_sql(): void
    {
        $this->assertSame([], $this->search('title = "x\' OR 1=1 --"'));
        $this->assertSame([], $this->search('title ~ "%"'));
        $this->assertCount(3, $this->search('title ~ ""'));
    }

    public function test_bad_queries_come_back_as_a_422_on_jql(): void
    {
        foreach (['nope = 1', 'title = "oops', 'status >', 'due = banana', 'points = abc', 'assignee = foo()', 'title ~ x ORDER BY password'] as $bad) {
            $this->getJson("/api/projects/{$this->project}/tasks?view=list&jql=".rawurlencode($bad))
                ->assertUnprocessable()->assertJsonValidationErrors('jql');
        }
    }

    public function test_a_viewer_with_a_narrow_row_scope_cannot_widen_it_with_a_query(): void
    {
        // The query is ANDed onto the caller's scoped query: an unrelated project id changes nothing.
        $this->assertSame([], $this->search('project = OTHER'));
        $this->assertCount(3, $this->search('project = JQ'));
    }

    public function test_the_query_language_is_gated_by_the_plan(): void
    {
        $this->setAcmeModules(['time_tracking']);

        $this->getJson("/api/projects/{$this->project}/tasks?view=list&jql=".rawurlencode('title ~ a'))
            ->assertUnprocessable()->assertJsonValidationErrors('jql');
        // Plain list still works without the module.
        $this->getJson("/api/projects/{$this->project}/tasks?view=list")->assertOk();
    }
}
