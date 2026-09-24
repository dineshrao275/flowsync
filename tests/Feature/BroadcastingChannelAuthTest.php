<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantContext;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class BroadcastingChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private User $member;

    private User $outsider;

    private Workspace $workspace;

    private Project $project;

    private array $channels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->acme = Tenant::where('slug', 'acme')->first();

        app(TenantContext::class)->setTenantId($this->acme->id);

        $this->member = User::where('email', 'admin@flowsync.test')->first();
        $this->outsider = User::where('email', 'editor@flowsync.test')->first();

        $this->workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Eng',
            'slug' => 'eng',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $this->workspace->id,
            'name' => 'Backend',
            'key' => 'be',
        ]);

        $lead = ProjectRole::where('tenant_id', $this->acme->id)->where('slug', 'lead')->first();
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
}
