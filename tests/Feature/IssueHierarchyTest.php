<?php

namespace Tests\Feature;

use App\Models\IssueType;
use Tests\Feature\Concerns\BuildsTmsFixtures;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P4.1 — issue hierarchy: levels per issue type, parent/epic rules, tree read model. */
class IssueHierarchyTest extends TestCase
{
    use BuildsTmsFixtures;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->buildProject();
        $this->loginAs('admin@flowsync.test');
    }

    private function url(string $path = ''): string
    {
        return "/api/projects/{$this->project->id}{$path}";
    }

    public function test_issue_types_carry_a_hierarchy_level(): void
    {
        $this->assertSame(0, IssueType::where('slug', 'initiative')->first()->level());
        $this->assertSame(1, IssueType::where('slug', 'epic')->first()->level());
        $this->assertSame(2, IssueType::where('slug', 'story')->first()->level());
        $this->assertSame(3, IssueType::where('slug', 'subtask')->first()->level());
    }

    public function test_a_story_links_to_an_epic_and_stays_on_the_board(): void
    {
        $epic = $this->apiTask($this->project, ['title' => 'Epic', 'issue_type_id' => $this->issueType('epic')]);
        $story = $this->apiTask($this->project, ['title' => 'Story', 'issue_type_id' => $this->issueType('story'), 'epic_id' => $epic['id']]);

        $this->assertSame($epic['id'], $story['epic_id']);
        $this->assertSame($epic['id'], $this->getJson($this->url("/tasks/{$story['id']}"))->json('task.epic.id'));

        $keys = collect($this->getJson($this->url('/tasks?view=board'))->json('board.statuses'))->flatMap(fn ($s) => collect($s['tasks'])->pluck('key'));
        $this->assertContains($story['key'], $keys->all());   // epic link is not parent_id
    }

    public function test_the_epic_link_must_point_at_an_epic_in_the_same_project(): void
    {
        $plain = $this->apiTask($this->project, ['title' => 'Plain task']);
        $this->postJson($this->url('/tasks'), ['title' => 'S', 'epic_id' => $plain['id']])->assertUnprocessable()->assertJsonValidationErrors('epic_id');

        $other = $this->buildProject('OT');
        $foreignEpic = $this->apiTask($other, ['title' => 'Foreign', 'issue_type_id' => $this->issueType('epic')]);
        $this->postJson($this->url('/tasks'), ['title' => 'S', 'epic_id' => $foreignEpic['id']])->assertUnprocessable()->assertJsonValidationErrors('epic_id');
    }

    public function test_epics_cannot_be_subtasks_and_subtasks_need_a_standard_parent(): void
    {
        $story = $this->apiTask($this->project, ['title' => 'Story', 'issue_type_id' => $this->issueType('story')]);
        $epic = $this->apiTask($this->project, ['title' => 'Epic', 'issue_type_id' => $this->issueType('epic')]);

        $this->postJson($this->url('/tasks'), ['title' => 'E2', 'issue_type_id' => $this->issueType('epic'), 'parent_id' => $story['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url('/tasks'), ['title' => 'Sub', 'issue_type_id' => $this->issueType('subtask'), 'parent_id' => $epic['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url('/tasks'), ['title' => 'Sub', 'issue_type_id' => $this->issueType('subtask'), 'parent_id' => $story['id']])->assertCreated();
    }

    public function test_a_subtask_cannot_take_an_epic_link_and_an_initiative_cannot_be_linked(): void
    {
        $epic = $this->apiTask($this->project, ['title' => 'Epic', 'issue_type_id' => $this->issueType('epic')]);
        $this->postJson($this->url('/tasks'), ['title' => 'Sub', 'issue_type_id' => $this->issueType('subtask'), 'epic_id' => $epic['id']])->assertUnprocessable()->assertJsonValidationErrors('epic_id');

        $initiative = $this->apiTask($this->project, ['title' => 'Init', 'issue_type_id' => $this->issueType('initiative')]);
        $this->postJson($this->url('/tasks'), ['title' => 'I2', 'issue_type_id' => $this->issueType('initiative'), 'epic_id' => $epic['id']])->assertUnprocessable();
        // epic -> initiative is allowed
        $this->putJson($this->url("/tasks/{$epic['id']}"), ['epic_id' => $initiative['id']])->assertOk()->assertJsonPath('task.epic_id', $initiative['id']);
    }

    public function test_an_issue_cannot_be_retyped_while_children_depend_on_its_type(): void
    {
        $epic = $this->apiTask($this->project, ['title' => 'Epic', 'issue_type_id' => $this->issueType('epic')]);
        $this->apiTask($this->project, ['title' => 'Story', 'issue_type_id' => $this->issueType('story'), 'epic_id' => $epic['id']]);

        $this->putJson($this->url("/tasks/{$epic['id']}"), ['issue_type_id' => $this->issueType('bug')])->assertUnprocessable()->assertJsonValidationErrors('issue_type_id');

        $parent = $this->apiTask($this->project, ['title' => 'Parent']);
        $this->apiTask($this->project, ['title' => 'Child', 'parent_id' => $parent['id']]);
        $this->putJson($this->url("/tasks/{$parent['id']}"), ['issue_type_id' => $this->issueType('epic')])->assertUnprocessable()->assertJsonValidationErrors('issue_type_id');
    }

    public function test_the_tree_nests_initiative_epic_story_subtask_and_groups_loose_issues(): void
    {
        $init = $this->apiTask($this->project, ['title' => 'Init', 'issue_type_id' => $this->issueType('initiative')]);
        $epic = $this->apiTask($this->project, ['title' => 'Epic', 'issue_type_id' => $this->issueType('epic'), 'epic_id' => $init['id']]);
        $story = $this->apiTask($this->project, ['title' => 'Story', 'issue_type_id' => $this->issueType('story'), 'epic_id' => $epic['id']]);
        $this->apiTask($this->project, ['title' => 'Sub', 'issue_type_id' => $this->issueType('subtask'), 'parent_id' => $story['id']]);
        $this->apiTask($this->project, ['title' => 'Loose']);

        $tree = $this->getJson($this->url('/hierarchy'))->assertOk()->json('tree');

        $this->assertSame('Init', $tree[0]['title']);
        $this->assertSame('Epic', $tree[0]['children'][0]['title']);
        $this->assertSame('Story', $tree[0]['children'][0]['children'][0]['title']);
        $this->assertSame('Sub', $tree[0]['children'][0]['children'][0]['children'][0]['title']);
        $this->assertSame('No epic', end($tree)['title']);
    }

    public function test_the_tree_is_closed_to_a_non_member(): void
    {
        $this->apiTask($this->project, ['title' => 'Secret']);
        $outsider = $this->member($this->project, 'outsider@flowsync.test', null);

        $this->loginAs($outsider->email);
        $this->getJson($this->url('/hierarchy'))->assertForbidden();
    }
}
