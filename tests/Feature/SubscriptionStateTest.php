<?php

namespace Tests\Feature;

use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class SubscriptionStateTest extends TestCase
{
    use IsolatesDatabase;

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    private function assign(Tenant $tenant, SubscriptionPlan $plan): void
    {
        app(SubscriptionService::class)->assign($tenant, $plan);
    }

    private function makeWorkspace(User $admin): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $admin->id,
            'name' => 'Quota Workspace',
            'slug' => 'quota-ws',
        ]);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);

        return $workspace;
    }

    private function makeProject(Workspace $workspace, User $admin): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => 'Quota Project',
            'key' => 'QP',
        ]);

        $leadRole = ProjectRole::where('slug', 'lead')->first();
        if ($leadRole) {
            $project->members()->attach($admin->id, ['project_role_id' => $leadRole->id, 'added_by' => $admin->id]);
        }

        $position = 0;
        foreach (config('task_statuses.statuses', []) as $status) {
            $position++;
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        return $project;
    }

    public function test_backfill_migration_assigns_explicit_subscription_state_to_orphaned_tenants(): void
    {
        $acme = $this->acme();
        $this->assertNull($acme->subscription_id);

        $migration = require database_path('migrations/system/2026_09_24_000020_backfill_tenant_subscriptions.php');
        $migration->up();

        $acme = $this->acme()->fresh();
        $this->assertNotNull($acme->subscription_id);
        $this->assertNotNull($acme->subscription);
        $this->assertSame(Subscription::STATUS_CANCELED, $acme->subscription->status);

        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        $this->assertNotNull($globex->subscription_id);
        $this->assertSame(Subscription::STATUS_CANCELED, $globex->subscription->status);

        // Idempotent re-run
        $migration->up();
        $this->assertSame($acme->subscription_id, $this->acme()->fresh()->subscription_id);
    }

    public function test_export_full_module_is_plan_gated_at_middleware_layer(): void
    {
        $acme = $this->acme();
        // Starter plan does NOT have 'export.full'
        $this->assign($acme, $this->plan('starter'));

        $this->loginAs('admin@flowsync.test');

        // Accessing my-export endpoints should 403 because starter lacks export.full
        $this->getJson('/api/my-export')->assertForbidden();
        $this->postJson('/api/my-export')->assertForbidden();

        // Enterprise plan DOES have 'export.full'
        $this->assign($acme, $this->plan('enterprise'));

        $this->getJson('/api/my-export')->assertOk();
    }

    public function test_attachment_upload_enforces_storage_quota(): void
    {
        Storage::fake('local');
        $acme = $this->acme();
        $admin = $this->loginAs('admin@flowsync.test');

        $workspace = $this->makeWorkspace($admin);
        $project = $this->makeProject($workspace, $admin);

        $status = $project->statuses()->first();
        $priority = Priority::first();

        $task = Task::create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
            'title' => 'Quota Task',
            'key' => 'QP-1',
            'sequence' => 1,
            'status_id' => $status?->id ?? 1,
            'priority_id' => $priority?->id ?? 1,
        ]);

        // Override tenant storage limit to 100 bytes to easily test quota
        $this->assign($acme, $this->plan('starter'));
        $acme->update([
            'limits_override' => ['storage_bytes' => 100],
        ]);

        // Uploading a 500-byte file exceeds 100-byte quota
        $file = UploadedFile::fake()->create('test.pdf', 500);

        $response = $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonPath('errors.file.0', fn ($msg) => str_contains($msg, 'Storage quota exceeded'));
    }
}
