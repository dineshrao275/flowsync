<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Models\TaskBranch;
use App\Models\TaskCommit;
use App\Models\TaskPullRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 5 - verified provider events become queryable task state and exactly one activity line per change. */
class GitEventProcessingTest extends TestCase
{
    use IsolatesDatabase;

    private const SECRET = 'whsec_events';

    private GitConnection $connection;

    private ProjectRepository $link;

    private int $projectId;

    /** @var array<string, int> key => task id */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true, 'git.providers.github.webhook_secret' => self::SECRET]);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $ws = $this->postJson('/api/workspaces', ['name' => 'Events WS'])->assertCreated()->json('workspace.id');
        $this->projectId = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Events', 'key' => 'EVT'])->assertCreated()->json('project.id');
        foreach (['One', 'Two'] as $title) {
            $task = $this->postJson("/api/projects/{$this->projectId}/tasks", ['title' => $title])->assertCreated()->json('task');
            $this->tasks[$task['key']] = $task['id'];
        }

        $this->connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active']);
        GitRepository::create(['git_connection_id' => $this->connection->id, 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'name' => 'api', 'owner' => 'acme-org', 'available' => true, 'html_url' => 'https://github.com/acme-org/api']);
        $this->connectTenant('acme');
        $this->link = ProjectRepository::create([
            'project_id' => $this->projectId, 'git_connection_uuid' => $this->connection->uuid, 'provider' => 'github', 'provider_repo_id' => 42,
            'full_name' => 'acme-org/api', 'html_url' => 'https://github.com/acme-org/api', 'sync_enabled' => true,
        ]);
    }

    private function key(int $n = 1): string
    {
        return array_keys($this->tasks)[$n - 1];
    }

    private function deliver(string $event, array $payload, ?string $uuid = null): TestResponse
    {
        $payload += ['installation' => ['id' => 501], 'repository' => ['id' => 42, 'full_name' => 'acme-org/api'], 'sender' => ['login' => 'octo', 'type' => 'User']];
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => $event, 'HTTP_X_GITHUB_DELIVERY' => $uuid ?? 'deliv-'.bin2hex(random_bytes(6)),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function pr(array $over = []): array
    {
        return array_replace_recursive([
            'id' => 9001, 'number' => 7, 'title' => "Fix login ({$this->key()})", 'body' => '', 'state' => 'open', 'draft' => false, 'merged' => false,
            'user' => ['login' => 'octo', 'avatar_url' => 'https://avatars.githubusercontent.com/u/1'], 'html_url' => 'https://github.com/acme-org/api/pull/7',
            'head' => ['ref' => 'fix/login'], 'base' => ['ref' => 'main'], 'merged_at' => null,
        ], $over);
    }

    private function activities(string $key): int
    {
        $this->connectTenant('acme');

        return Activity::where('action', 'task.vcs_event')->where('subject_type', Task::class)->where('subject_id', $this->tasks[$key])->count();
    }

    public function test_an_opened_pull_request_links_its_task_once_and_follows_state_changes(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr()])->assertStatus(202);

        $this->connectTenant('acme');
        $row = TaskPullRequest::firstOrFail();
        $this->assertSame($this->tasks[$this->key()], $row->task_id);
        $this->assertSame(['open', 7, 'octo', 'fix/login', 'main'], [$row->state, $row->number, $row->author_login, $row->head_branch, $row->base_branch]);
        $this->assertSame(1, $this->activities($this->key()));
        $activity = Activity::where('action', 'task.vcs_event')->firstOrFail();
        $this->assertSame(['pull_request', 'open', 7, 'github', 'acme-org/api'], [$activity->data['kind'], $activity->data['state'], $activity->data['number'], $activity->data['provider'], $activity->data['repo']]);
        $this->assertSame('https://github.com/acme-org/api/pull/7', $activity->data['url']);
        $this->assertNotEmpty($activity->data['delivery']);

        // An edit and a new push update the row but say nothing.
        $this->deliver('pull_request', ['action' => 'edited', 'pull_request' => $this->pr(['title' => "Fix login again ({$this->key()})"])]);
        $this->deliver('pull_request', ['action' => 'synchronize', 'pull_request' => $this->pr()]);
        $this->assertSame(1, $this->activities($this->key()));
        $this->assertSame(1, TaskPullRequest::count());

        $this->deliver('pull_request', ['action' => 'closed', 'pull_request' => $this->pr(['state' => 'closed', 'merged' => true, 'merged_at' => '2026-11-03T10:00:00Z'])]);
        $this->assertSame('merged', TaskPullRequest::firstOrFail()->state);
        $this->assertNotNull(TaskPullRequest::firstOrFail()->merged_at);
        $this->assertSame(2, $this->activities($this->key()));
    }

    public function test_a_draft_and_a_closed_unmerged_pull_request_have_their_own_states(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr(['draft' => true])]);
        $this->assertSame('draft', TaskPullRequest::firstOrFail()->state);

        $this->deliver('pull_request', ['action' => 'closed', 'pull_request' => $this->pr(['state' => 'closed'])]);
        $this->assertSame('closed', TaskPullRequest::firstOrFail()->state);
    }

    public function test_keys_are_found_in_the_body_and_branch_case_insensitively_and_one_pr_can_link_two_tasks(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr([
            'title' => 'No key here', 'body' => 'Closes '.strtolower($this->key(2)), 'head' => ['ref' => 'feature/'.$this->key(1).'-thing'],
        ])]);

        $this->connectTenant('acme');
        $this->assertEqualsCanonicalizing(array_values($this->tasks), TaskPullRequest::pluck('task_id')->all());
    }

    public function test_unknown_keys_unlinked_repositories_and_paused_links_are_ignored(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr(['title' => 'Fix ZZZ-999'])])->assertStatus(202);
        $this->deliver('pull_request', ['action' => 'opened', 'repository' => ['id' => 4242, 'full_name' => 'acme-org/other'], 'pull_request' => $this->pr()]);
        $this->connectTenant('acme');
        $this->link->update(['sync_enabled' => false]);
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr()]);

        $this->connectTenant('acme');
        $this->assertSame(0, TaskPullRequest::count());
        $this->assertSame(0, Activity::where('action', 'task.vcs_event')->count());
    }

    public function test_a_task_in_another_project_is_not_matched_unless_the_link_allows_it(): void
    {
        $ws = $this->postJson('/api/workspaces', ['name' => 'Other WS'])->assertCreated()->json('workspace.id');
        $other = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Other', 'key' => 'OTH'])->assertCreated()->json('project.id');
        $foreign = $this->postJson("/api/projects/$other/tasks", ['title' => 'Elsewhere'])->assertCreated()->json('task');

        $payload = ['action' => 'opened', 'pull_request' => $this->pr(['title' => "Touches {$foreign['key']}"])];
        $this->deliver('pull_request', $payload);
        $this->connectTenant('acme');
        $this->assertSame(0, TaskPullRequest::count());

        $this->link->update(['allow_cross_project' => true]);
        $this->deliver('pull_request', $payload);
        $this->connectTenant('acme');
        $this->assertSame([$foreign['id']], TaskPullRequest::pluck('task_id')->all());
    }

    public function test_bot_senders_and_forged_urls_are_handled(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'sender' => ['login' => 'dependabot[bot]', 'type' => 'Bot'], 'pull_request' => $this->pr()]);
        $this->connectTenant('acme');
        $this->assertSame(0, TaskPullRequest::count());

        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr(['html_url' => 'https://evil.example.com/phish', 'user' => ['avatar_url' => 'http://insecure/x.png']])]);
        $this->connectTenant('acme');
        $this->assertNull(TaskPullRequest::firstOrFail()->html_url);
        $this->assertNull(TaskPullRequest::firstOrFail()->author_avatar_url);
    }

    public function test_a_second_delivery_of_the_same_state_adds_no_activity(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr()], 'deliv-first0001');
        $this->deliver('pull_request', ['action' => 'reopened', 'pull_request' => $this->pr()], 'deliv-second001');
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr()], 'deliv-first0001');

        $this->assertSame(1, $this->activities($this->key()));
    }

    public function test_a_push_records_commits_by_message_and_branch_and_skips_bots_and_replays(): void
    {
        $push = ['ref' => 'refs/heads/feature/'.$this->key(2).'-work', 'commits' => [
            ['id' => 'aaa111', 'message' => "Implement {$this->key(1)} part\n\nlong body", 'url' => 'https://github.com/acme-org/api/commit/aaa111', 'timestamp' => '2026-11-03T09:00:00Z', 'author' => ['username' => 'octo', 'name' => 'Octo Cat']],
            ['id' => 'bbb222', 'message' => 'no key but on the branch', 'url' => 'https://github.com/acme-org/api/commit/bbb222', 'author' => ['username' => 'octo']],
            ['id' => 'ccc333', 'message' => "bump {$this->key(1)}", 'author' => ['username' => 'renovate[bot]']],
        ]];
        $this->deliver('push', $push);

        $this->connectTenant('acme');
        $byTask = TaskCommit::all()->groupBy('task_id');
        $this->assertSame(['aaa111'], $byTask[$this->tasks[$this->key(1)]]->pluck('sha')->all());
        $this->assertSame(['aaa111', 'bbb222'], $byTask[$this->tasks[$this->key(2)]]->pluck('sha')->sort()->values()->all(), 'the branch carries the key onto every commit');
        $this->assertSame('Implement '.$this->key(1).' part', TaskCommit::where('sha', 'aaa111')->where('task_id', $this->tasks[$this->key(1)])->value('message'));
        $this->assertSame(0, TaskCommit::where('sha', 'ccc333')->count(), 'bot commits are skipped');

        $before = $this->activities($this->key(2));
        $this->deliver('push', $push);
        $this->assertSame($before, $this->activities($this->key(2)), 'the same commits pushed again are news to nobody');
    }

    public function test_pushes_are_capped_and_deletions_and_tags_ignored(): void
    {
        config(['git.max_commits_per_push' => 3]);
        $commits = array_map(fn ($i) => ['id' => "sha{$i}", 'message' => "{$this->key()} commit {$i}", 'author' => ['username' => 'octo']], range(1, 8));
        $this->deliver('push', ['ref' => 'refs/heads/main', 'commits' => $commits]);
        $this->connectTenant('acme');
        $this->assertSame(3, TaskCommit::count());

        $this->deliver('push', ['ref' => 'refs/heads/main', 'deleted' => true, 'commits' => [['id' => 'zzz', 'message' => $this->key()]]]);
        $this->deliver('push', ['ref' => 'refs/tags/v1', 'commits' => [['id' => 'yyy', 'message' => $this->key()]]]);
        $this->connectTenant('acme');
        $this->assertSame(3, TaskCommit::count());
    }

    public function test_branches_are_recorded_when_created_and_marked_when_deleted(): void
    {
        $name = 'feature/'.$this->key().'-login';
        $this->deliver('create', ['ref_type' => 'branch', 'ref' => $name]);
        $this->deliver('create', ['ref_type' => 'tag', 'ref' => 'v1.0-'.$this->key()]);
        $this->deliver('create', ['ref_type' => 'branch', 'ref' => 'chore/no-key']);

        $this->connectTenant('acme');
        $this->assertSame([$name], TaskBranch::pluck('name')->all());
        $this->assertSame(1, $this->activities($this->key()));

        $this->deliver('delete', ['ref_type' => 'branch', 'ref' => $name]);
        $this->connectTenant('acme');
        $this->assertNotNull(TaskBranch::firstOrFail()->deleted_at);

        $this->deliver('create', ['ref_type' => 'branch', 'ref' => $name]);
        $this->connectTenant('acme');
        $this->assertNull(TaskBranch::firstOrFail()->deleted_at, 'a recreated branch is live again');
        $this->assertSame(1, TaskBranch::count());
        $this->assertSame(2, $this->activities($this->key()));
    }

    public function test_a_published_release_is_announced_once_and_a_review_per_review(): void
    {
        $release = ['action' => 'published', 'release' => ['id' => 77, 'name' => "v2 {$this->key()}", 'tag_name' => 'v2', 'html_url' => 'https://github.com/acme-org/api/releases/v2', 'body' => '']];
        $this->deliver('release', $release);
        $this->deliver('release', $release);
        $this->deliver('release', ['action' => 'edited'] + $release);
        $this->assertSame(1, $this->activities($this->key()));

        $review = fn (string $state, int $id) => ['action' => 'submitted', 'review' => ['id' => $id, 'state' => $state, 'html_url' => 'https://github.com/acme-org/api/pull/7#r'.$id], 'pull_request' => $this->pr()];
        $this->deliver('pull_request_review', $review('approved', 1));
        $this->deliver('pull_request_review', $review('changes_requested', 2));
        $this->deliver('pull_request_review', ['action' => 'dismissed'] + $review('approved', 1));
        $this->connectTenant('acme');
        $states = Activity::where('action', 'task.vcs_event')->get()->pluck('data')->where('kind', 'review')->pluck('state')->all();
        $this->assertEqualsCanonicalizing(['approved', 'changes_requested', 'dismissed'], $states);
    }

    public function test_checks_move_the_state_of_the_open_pull_request_and_only_on_change(): void
    {
        $this->deliver('pull_request', ['action' => 'opened', 'pull_request' => $this->pr()]);
        $base = $this->activities($this->key());

        $suite = fn (string $status, ?string $conclusion) => ['action' => 'completed', 'check_suite' => ['status' => $status, 'conclusion' => $conclusion, 'head_branch' => 'fix/login', 'pull_requests' => [['number' => 7]]]];
        $this->deliver('check_suite', $suite('in_progress', null));
        $this->connectTenant('acme');
        $this->assertSame('pending', TaskPullRequest::firstOrFail()->checks_state);

        $this->deliver('check_suite', $suite('completed', 'success'));
        $this->assertSame('success', TaskPullRequest::firstOrFail()->checks_state);
        $this->deliver('check_suite', $suite('completed', 'success'));
        $this->assertSame($base + 2, $this->activities($this->key()), 'pending then success; the repeat says nothing');

        $this->deliver('check_run', ['action' => 'completed', 'check_run' => ['status' => 'completed', 'conclusion' => 'failure', 'pull_requests' => [['number' => 7]]]]);
        $this->assertSame('failure', TaskPullRequest::firstOrFail()->checks_state);

        $this->deliver('status', ['state' => 'success', 'branches' => [['name' => 'fix/login']]]);
        $this->assertSame('success', TaskPullRequest::firstOrFail()->checks_state, 'a commit status finds the PR by its head branch');
    }

    public function test_checks_leave_merged_pull_requests_alone(): void
    {
        $this->deliver('pull_request', ['action' => 'closed', 'pull_request' => $this->pr(['state' => 'closed', 'merged' => true, 'merged_at' => '2026-11-03T10:00:00Z'])]);
        $this->deliver('check_suite', ['action' => 'completed', 'check_suite' => ['status' => 'completed', 'conclusion' => 'failure', 'pull_requests' => [['number' => 7]]]]);

        $this->connectTenant('acme');
        $this->assertSame('none', TaskPullRequest::firstOrFail()->checks_state);
    }

    public function test_a_renamed_repository_updates_every_following_project(): void
    {
        GitRepository::where('provider_repo_id', 42)->update(['full_name' => 'acme-org/api']);
        $this->deliver('repository', ['action' => 'renamed', 'repository' => ['id' => 42, 'full_name' => 'acme-org/api-v2', 'name' => 'api-v2', 'owner' => ['login' => 'acme-org'], 'html_url' => 'https://github.com/acme-org/api-v2']]);

        $this->connectTenant('acme');
        $this->assertSame('acme-org/api-v2', $this->link->fresh()->full_name);
        $this->assertSame('https://github.com/acme-org/api-v2', $this->link->fresh()->html_url);
    }

    public function test_another_tenants_installation_with_the_same_repository_name_never_touches_this_tenants_tasks(): void
    {
        $other = GitConnection::create(['tenant_id' => $this->globex()->id, 'provider' => 'github', 'installation_id' => 777, 'account_login' => 'globex-org', 'account_type' => 'Organization', 'status' => 'active']);

        $this->deliver('pull_request', ['action' => 'opened', 'installation' => ['id' => 777], 'pull_request' => $this->pr()]);

        $this->connectTenant('acme');
        $this->assertSame(0, TaskPullRequest::count());
        $this->assertSame(0, $this->activities($this->key()));
        $this->assertSame($other->tenant_id, (int) \App\Models\GitWebhookDelivery::latest('id')->value('tenant_id'));
    }

    public function test_the_migration_creates_the_documented_tables(): void
    {
        $this->connectTenant('acme');
        foreach (['task_pull_requests' => ['checks_state', 'head_branch', 'merged_at'], 'task_commits' => ['sha', 'message'], 'task_branches' => ['name', 'deleted_at'], 'git_identities' => ['login', 'user_id'], 'git_event_links' => ['delivery_uuid', 'kind']] as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }
        $this->assertTrue(collect(Schema::getIndexes('git_event_links'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['delivery_uuid', 'task_id', 'kind', 'provider_object_id']));
    }
}
