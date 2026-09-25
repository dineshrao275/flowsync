<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class BroadcastingChannelAuthTest extends TestCase
{
    use IsolatesDatabase;

    private User $member;

    private User $outsider;

    private Workspace $workspace;

    private Project $project;

    private array $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::where('email', 'admin@flowsync.test')->first();
        $this->outsider = User::where('email', 'editor@flowsync.test')->first();

        $this->workspace = Workspace::create([
            'name' => 'Eng',
            'slug' => 'eng',
        ]);

        $this->project = Project::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Backend',
            'key' => 'be',
        ]);

        $lead = ProjectRole::where('slug', 'lead')->first();
        $this->project->members()->attach($this->member->id, ['project_role_id' => $lead->id]);

        $this->channels = Broadcast::driver()->getChannels()->all();
    }

    private function userChannelCallback(): callable
    {
        return $this->channels['user.{id}'];
    }

    private function projectChannelCallback(): callable
    {
        return $this->channels['project.{id}'];
    }

    private function workspaceChannelCallback(): callable
    {
        return $this->channels['workspace.{id}'];
    }

    public function test_user_channel_allows_only_self(): void
    {
        $callback = $this->userChannelCallback();

        $this->assertTrue((bool) $callback($this->member, $this->member->id));
        $this->assertFalse((bool) $callback($this->outsider, $this->member->id));
    }

    public function test_project_channel_allows_member_but_not_outsider(): void
    {
        $callback = $this->projectChannelCallback();

        $this->assertTrue((bool) $callback($this->member, $this->project->id));
        $this->assertFalse((bool) $callback($this->outsider, $this->project->id));
    }

    public function test_workspace_channel_allows_workspace_member(): void
    {
        $callback = $this->workspaceChannelCallback();

        $this->workspace->members()->attach($this->outsider->id, ['role' => 'member']);

        $this->assertTrue((bool) $callback($this->outsider, $this->workspace->id));
        $this->assertFalse((bool) $callback($this->member, $this->workspace->id));
    }

    public function test_broadcast_auth_route_is_registered(): void
    {
        $routes = app('router')->getRoutes();
        $this->assertNotNull($routes->match(request()->create('/broadcasting/auth', 'POST'))->getAction()); // @phpstan-ignore-line
    }

    public function test_broadcast_auth_route_switches_to_the_tenant_connection(): void
    {
        // Without `switch_tenant` the session user is resolved on the default
        // (central) connection, where tenant users do not exist, so every
        // channel auth request answers 403.
        $route = app('router')->getRoutes()->match(request()->create('/broadcasting/auth', 'POST')); // @phpstan-ignore-line

        $this->assertContains('switch_tenant', $route->gatherMiddleware());
    }

    public function test_member_authorizes_own_channel_over_http(): void
    {
        $this->usePusherBroadcaster();
        $this->loginAs('admin@flowsync.test');

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.'.$this->member->id,
        ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-project.'.$this->project->id,
        ])->assertOk();
    }

    public function test_outsider_cannot_authorize_another_users_channel_over_http(): void
    {
        $this->usePusherBroadcaster();
        $this->loginAs('editor@flowsync.test');

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.'.$this->member->id,
        ])->assertForbidden();
    }

    public function test_guests_cannot_authorize_channels_over_http(): void
    {
        $this->usePusherBroadcaster();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.'.$this->member->id,
        ])->assertUnauthorized();
    }

    /**
     * The suite runs with BROADCAST_CONNECTION=null, whose auth() is a no-op
     * that always returns 200/empty, so the real channel callbacks would never
     * run. Point the manager at the pusher broadcaster with throwaway
     * credentials and re-register the channel callbacks on it.
     */
    private function usePusherBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }

    private function loginAs(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }
}
