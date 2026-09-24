<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $acme = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->acme = Tenant::where('slug', 'acme')->first();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    public function test_owner_can_create_label(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/labels", [
            'name' => 'bug',
            'color' => '#ef4444',
        ])->assertCreated();

        $this->assertDatabaseHas('labels', [
            'tenant_id' => $this->acme->id,
            'workspace_id' => $ws->id,
            'name' => 'bug',
        ]);
    }

    public function test_label_names_are_unique_per_workspace(): void
    {
        $ws = $this->makeWorkspace();
        $ws2 = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => 'Marketing',
            'slug' => 'marketing',
        ]);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/labels", ['name' => 'bug'])->assertCreated();
        $this->postJson("/api/workspaces/{$ws->id}/labels", ['name' => 'bug'])->assertUnprocessable();

        $this->postJson("/api/workspaces/{$ws2->id}/labels", ['name' => 'bug'])->assertCreated();
    }

    public function test_plain_member_cannot_create_label(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/labels", ['name' => 'bug'])
            ->assertForbidden();
    }

    public function test_workspace_admin_can_create_label(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'admin', 'added_by' => $this->admin()->id]);
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/labels", ['name' => 'bug'])->assertCreated();
    }

    public function test_member_can_list_and_view_labels(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $ws->labels()->create(['tenant_id' => $this->acme->id, 'name' => 'bug', 'color' => '#ef4444']);
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/workspaces/{$ws->id}/labels")
            ->assertOk()
            ->assertJsonCount(1, 'labels')
            ->assertJsonPath('labels.0.name', 'bug');
    }

    public function test_owner_can_update_and_delete_label(): void
    {
        $ws = $this->makeWorkspace();
        $label = $ws->labels()->create(['tenant_id' => $this->acme->id, 'name' => 'bug', 'color' => '#ef4444']);
        $this->login('admin@flowsync.test');

        $this->putJson("/api/labels/{$label->id}", ['name' => 'defect', 'color' => '#f59e0b'])
            ->assertOk()
            ->assertJsonPath('label.name', 'defect');

        $this->deleteJson("/api/labels/{$label->id}")->assertOk();
        $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    }

    public function test_plain_member_cannot_update_or_delete_label(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $label = $ws->labels()->create(['tenant_id' => $this->acme->id, 'name' => 'bug']);
        $this->login('viewer@flowsync.test');

        $this->putJson("/api/labels/{$label->id}", ['name' => 'defect'])->assertForbidden();
        $this->deleteJson("/api/labels/{$label->id}")->assertForbidden();
    }

    public function test_cross_tenant_label_is_not_accessible(): void
    {
        $ws = $this->makeWorkspace();
        $label = $ws->labels()->create(['tenant_id' => $this->acme->id, 'name' => 'bug']);
        $this->login('owner@globex.test');

        $this->getJson("/api/workspaces/{$ws->id}/labels")->assertNotFound();
        $this->putJson("/api/labels/{$label->id}", ['name' => 'hack'])->assertNotFound();
        $this->deleteJson("/api/labels/{$label->id}")->assertNotFound();
    }
}
