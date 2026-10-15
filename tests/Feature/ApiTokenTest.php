<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use App\Models\IntegrationLog;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P2.7 — personal API tokens, the /api/v1 door, idempotency, throttle and the integration log. */
class ApiTokenTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    /** Creates a token through the session API, then drops the session so only the bearer is left. */
    private function issue(array $over = [], string $email = 'admin@flowsync.test'): string
    {
        $this->login($email);
        $plain = $this->postJson('/api/api-tokens', $over + ['name' => 'CI', 'abilities' => ['workspaces.view'], 'can_write' => false])
            ->assertCreated()->json('plaintext');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->flushSession();

        return $plain;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function project(): Project
    {
        $this->connectTenant('acme');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $ws = Workspace::create(['created_by' => $admin->id, 'name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $project = Project::create(['workspace_id' => $ws->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P', 'key' => 'PP']);
        $project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }

        return $project;
    }

    public function test_the_plaintext_is_shown_once_and_only_a_hash_is_stored(): void
    {
        $this->login();
        $res = $this->postJson('/api/api-tokens', ['name' => 'CI', 'abilities' => ['workspaces.view']])->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^fst_\d+_[A-Za-z0-9]{40}$/', $res['plaintext']);
        $row = ApiToken::firstOrFail();
        $this->assertNotSame($res['plaintext'], $row->token_hash);
        $this->assertSame(hash('sha256', $res['plaintext']), $row->token_hash);
        $listed = $this->getJson('/api/api-tokens')->assertOk()->json();
        $this->assertArrayNotHasKey('plaintext', $listed['tokens'][0]);
        $this->assertArrayNotHasKey('token_hash', $listed['tokens'][0]);
    }

    public function test_management_needs_the_permission_and_the_api_module(): void
    {
        $this->login('viewer@flowsync.test');
        $this->getJson('/api/api-tokens')->assertForbidden();
        $this->postJson('/api/auth/logout');

        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());
        $this->login();
        $this->getJson('/api/api-tokens')->assertForbidden()->assertHeader('X-Module-Denied', 'api');
        $this->postJson('/api/api-tokens', ['name' => 'x', 'abilities' => ['workspaces.view']])->assertForbidden();
    }

    public function test_a_token_cannot_carry_more_than_its_creator_or_the_catalog(): void
    {
        $this->login();
        $this->postJson('/api/api-tokens', ['name' => 'x', 'abilities' => ['roles.manage']])->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $this->postJson('/api/api-tokens', ['name' => 'x', 'abilities' => []])->assertUnprocessable();
        $this->postJson('/api/api-tokens', ['name' => 'x', 'abilities' => ['workspaces.view'], 'expires_at' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/api-tokens', ['name' => 'x', 'abilities' => ['workspaces.view'], 'rate_limit' => 100000])->assertUnprocessable();
    }

    public function test_a_bearer_token_reaches_v1_without_a_session(): void
    {
        $token = $this->issue();

        $res = $this->getJson('/api/v1/me', $this->bearer($token))->assertOk()->json();
        $this->assertSame('admin@flowsync.test', $res['user']['email']);
        $this->assertSame('acme', $res['tenant']['slug']);
        $this->assertSame(['workspaces.view'], $res['token']['abilities']);
        $this->assertNotNull(ApiToken::first()->last_used_at);
    }

    public function test_missing_malformed_unknown_and_revoked_tokens_all_answer_401(): void
    {
        $token = $this->issue();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer('nonsense'))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer('fst_'.$this->acme()->id.'_'.str_repeat('a', 40)))->assertUnauthorized();

        $this->connectTenant('acme');
        ApiToken::query()->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->issue();
        $this->connectTenant('acme');
        ApiToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_a_token_only_works_inside_its_own_tenant(): void
    {
        $token = $this->issue();
        // Re-point the same random part at another tenant: that tenant's database holds no such hash.
        $forged = preg_replace('/^fst_\d+_/', 'fst_'.$this->globex()->id.'_', $token);

        $this->getJson('/api/v1/me', $this->bearer($forged))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
    }

    public function test_the_session_cookie_alone_does_not_open_v1(): void
    {
        $this->login();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_token_without_the_ability_is_refused_at_the_route(): void
    {
        $this->login();
        $plain = $this->postJson('/api/api-tokens', ['name' => 'hr', 'abilities' => ['hrms.view']])->assertCreated()->json('plaintext');
        $this->postJson('/api/auth/logout');
        $this->flushSession();

        $this->getJson('/api/v1/projects', $this->bearer($plain))->assertForbidden()->assertSee('workspaces.view');
    }

    public function test_the_owner_losing_their_rights_narrows_the_token(): void
    {
        $project = $this->project();
        $token = $this->issue(['can_write' => true]);
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Before'], $this->bearer($token))->assertCreated();

        // The ability stays on the token, but the owner no longer manages workspaces or belongs to the project.
        $owner = User::findOrFail(ApiToken::firstOrFail()->user_id);
        $owner->roles()->sync([Role::where('slug', 'viewer')->firstOrFail()->id]);
        $project->members()->detach($owner->id);

        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'After'], $this->bearer($token))->assertForbidden();
        $this->assertSame(1, Task::count());
    }

    public function test_a_read_only_token_cannot_write_and_a_write_token_can(): void
    {
        $project = $this->project();
        $readOnly = $this->issue();
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Nope'], $this->bearer($readOnly))
            ->assertForbidden()->assertJsonPath('code', 'token_read_only');
        $this->assertSame(0, Task::count());

        $writer = $this->issue(['name' => 'writer', 'can_write' => true]);
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Yes'], $this->bearer($writer))->assertCreated();
        $this->getJson("/api/v1/projects/{$project->id}/tasks", $this->bearer($writer))->assertOk();
        $this->getJson('/api/v1/projects', $this->bearer($writer))->assertOk();
    }

    public function test_idempotency_key_replays_without_running_twice_and_rejects_a_different_request(): void
    {
        $project = $this->project();
        $token = $this->issue(['can_write' => true]);
        $headers = $this->bearer($token) + ['Idempotency-Key' => 'order-0001-abcdef'];

        $first = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Once'], $headers)->assertCreated();
        $second = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Once'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('task.id'), $second->json('task.id'));
        $this->assertSame(1, Task::count());
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Different'], $headers)->assertUnprocessable();
        $this->assertSame(1, Task::count());
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'x'], $this->bearer($token) + ['Idempotency-Key' => 'short'])->assertUnprocessable();
    }

    public function test_the_per_token_rate_limit_applies_to_that_token_only(): void
    {
        $limited = $this->issue(['name' => 'limited', 'rate_limit' => 2]);
        $free = $this->issue(['name' => 'free']);

        $this->getJson('/api/v1/me', $this->bearer($limited))->assertOk();
        $this->getJson('/api/v1/me', $this->bearer($limited))->assertOk();
        $this->getJson('/api/v1/me', $this->bearer($limited))->assertStatus(429);
        $this->getJson('/api/v1/me', $this->bearer($free))->assertOk();
    }

    public function test_every_call_lands_in_the_integration_log_and_only_the_owner_reads_it(): void
    {
        $token = $this->issue();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/projects', $this->bearer($token))->assertOk();

        $this->connectTenant('acme');
        $this->assertSame(2, IntegrationLog::count());
        $this->assertSame(['api/v1/projects', 'api/v1/me'], IntegrationLog::orderByDesc('id')->pluck('path')->all());
        $id = ApiToken::firstOrFail()->id;

        // Another person with the permission cannot see or revoke it: 404, not 403.
        $other = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $other->roles()->first()->permissions()->syncWithoutDetaching(Permission::where('slug', 'api.manage')->pluck('id'));
        $this->flushSession();
        $this->login('editor@flowsync.test');
        $this->getJson("/api/api-tokens/{$id}/logs")->assertNotFound();
        $this->deleteJson("/api/api-tokens/{$id}")->assertNotFound();
        $this->assertNull(ApiToken::find($id)->revoked_at);
        $this->assertSame([], $this->getJson('/api/api-tokens')->json('tokens'));
    }

    public function test_revoking_kills_the_token(): void
    {
        $token = $this->issue();
        $this->login();
        $this->connectTenant('acme');
        $id = ApiToken::firstOrFail()->id;
        $this->deleteJson("/api/api-tokens/{$id}")->assertOk();
        $this->postJson('/api/auth/logout');
        $this->flushSession();

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        $this->connectTenant('acme');
        $this->assertNotNull(ApiToken::find($id)->revoked_at);
    }

    public function test_the_plan_module_gates_the_v1_routes(): void
    {
        $token = $this->issue();
        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());

        $this->getJson('/api/v1/me', $this->bearer($token))->assertForbidden()->assertHeader('X-Module-Denied', 'api');
    }

    public function test_prune_removes_old_logs_and_idempotency_keys(): void
    {
        $token = $this->issue();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $this->connectTenant('acme');
        $id = ApiToken::firstOrFail()->id;
        IntegrationLog::query()->update(['created_at' => now()->subDays(40)]);
        ApiIdempotencyKey::create(['token_id' => $id, 'key' => 'old-key-0001', 'fingerprint' => hash('sha256', 'GET /api/v1/me')]);
        ApiIdempotencyKey::query()->update(['created_at' => now()->subDays(3)]);

        Artisan::call('events:prune', ['--all' => true]);
        $this->connectTenant('acme');

        $this->assertSame(0, IntegrationLog::count());
        $this->assertSame(0, ApiIdempotencyKey::count());
    }
}
