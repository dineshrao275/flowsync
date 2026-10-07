<?php

namespace Tests\Feature;

use App\Jobs\BuildTenantExportJob;
use App\Models\ExportRun;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\Workspace;
use App\Services\ExportService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Queue;
use Tests\IsolatesDatabase;
use Tests\TestCase;
use ZipArchive;

class TenantExportTest extends TestCase
{
    use IsolatesDatabase;

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function globex(): Tenant
    {
        return Tenant::where('slug', 'globex')->firstOrFail();
    }

    private function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    private function assign(Tenant $tenant, SubscriptionPlan $plan): void
    {
        app(SubscriptionService::class)->assign($tenant, $plan);
    }

    public function test_export_endpoints_require_export_full_module(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('starter'));
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/my-export')->assertForbidden();
        $this->postJson('/api/my-export', ['categories' => ['workspaces']])->assertForbidden();
    }

    public function test_non_admin_cannot_queue_exports(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $this->loginAs('editor@flowsync.test');

        $this->postJson('/api/my-export', ['categories' => ['workspaces']])->assertForbidden();
    }

    public function test_export_history_is_scoped_to_the_initiating_user(): void
    {
        Queue::fake([BuildTenantExportJob::class]);

        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $this->loginAs('admin@flowsync.test');

        $runId = $this->postJson('/api/my-export', ['categories' => ['workspaces']])
            ->assertStatus(202)
            ->json('run.id');

        $this->postJson('/api/users', [
            'name' => 'Second Admin',
            'email' => 'admin2@flowsync.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'roles' => ['admin'],
        ])->assertCreated();

        $this->loginAs('admin2@flowsync.test');

        $this->assertSame([], $this->getJson('/api/my-export')->assertOk()->json('runs'));
        $this->getJson("/api/my-export/{$runId}")->assertNotFound();
    }

    public function test_admin_can_queue_export_with_selected_categories(): void
    {
        Queue::fake([BuildTenantExportJob::class]);

        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $this->loginAs('admin@flowsync.test');

        $response = $this->postJson('/api/my-export', [
            'categories' => ['workspaces', 'projects'],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('run.status', ExportRun::STATUS_PENDING)
            ->assertJsonPath('run.categories', ['workspaces', 'projects']);

        $this->assertDatabaseHas('export_runs', [
            'status' => ExportRun::STATUS_PENDING,
        ]);

        Queue::assertPushed(BuildTenantExportJob::class, fn ($job) => $job->tenantId === $acme->id);
    }

    public function test_invalid_categories_are_rejected(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/my-export', [
            'categories' => ['invalid_category_xyz'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['categories.0']);
    }

    public function test_build_export_job_creates_valid_zip_with_manifest_and_csvs(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $admin = $this->loginAs('admin@flowsync.test');

        // Create some sample workspace data to be exported
        Workspace::create([
            'created_by' => $admin->id,
            'name' => 'Export Workspace',
            'slug' => 'export-ws',
        ]);

        $run = ExportRun::create([
            'user_id' => $admin->id,
            'categories' => ['workspaces'],
            'status' => ExportRun::STATUS_PENDING,
        ]);

        // Run the job synchronously
        $job = new BuildTenantExportJob($acme->id, $run->id);
        $job->handle(app(ExportService::class), $this->dbm);

        $run = $run->fresh();
        $this->assertSame(ExportRun::STATUS_READY, $run->status);
        $this->assertNotNull($run->file_path);
        $this->assertGreaterThan(0, $run->file_size);
        $this->assertNotNull($run->expires_at);

        // Verify the ZIP archive structure
        $fullPath = storage_path('app/private/'.$run->file_path);
        $this->assertFileExists($fullPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($fullPath));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('workspaces.csv'));

        $manifestContent = $zip->getFromName('manifest.json');
        $manifest = json_decode($manifestContent, true);
        $this->assertSame($run->id, $manifest['run_id']);
        $this->assertContains('workspaces', $manifest['categories']);

        $workspacesCsv = $zip->getFromName('workspaces.csv');
        $this->assertStringContainsString('Export Workspace', $workspacesCsv);

        $zip->close();
    }

    public function test_signed_download_streams_zip_and_verifies_signature(): void
    {
        $acme = $this->acme();
        $this->assign($acme, $this->plan('enterprise'));
        $admin = $this->loginAs('admin@flowsync.test');

        $run = ExportRun::create([
            'user_id' => $admin->id,
            'categories' => ['workspaces'],
            'status' => ExportRun::STATUS_PENDING,
        ]);

        $job = new BuildTenantExportJob($acme->id, $run->id);
        $job->handle(app(ExportService::class), $this->dbm);

        // Fetch show to get signed download URL
        $showResponse = $this->getJson("/api/my-export/{$run->id}")
            ->assertOk()
            ->assertJsonPath('run.status', ExportRun::STATUS_READY);

        $downloadUrl = $showResponse->json('run.download_url');
        $this->assertNotNull($downloadUrl);

        // Valid signed download works without session
        $this->flushSession();
        $response = $this->get($downloadUrl);
        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        // Tampered URL fails 403
        $tamperedUrl = $downloadUrl.'&tampered=1';
        $this->get($tamperedUrl)->assertForbidden();
    }

    public function test_cross_tenant_isolation_on_signed_download(): void
    {
        $acme = $this->acme();
        $globex = $this->globex();
        $this->assign($acme, $this->plan('enterprise'));
        $admin = $this->loginAs('admin@flowsync.test');

        $run = ExportRun::create([
            'user_id' => $admin->id,
            'categories' => ['workspaces'],
            'status' => ExportRun::STATUS_PENDING,
        ]);

        $job = new BuildTenantExportJob($acme->id, $run->id);
        $job->handle(app(ExportService::class), $this->dbm);

        // Sign for globex pointing to acme's run ID
        $crossSignedUrl = url()->temporarySignedRoute(
            'exports.download',
            now()->addHour(),
            ['run' => $run->id, 'tenant' => $globex->id],
        );

        $this->flushSession();
        // Globex database does not have this run ID -> 404
        $this->get($crossSignedUrl)->assertNotFound();
    }
}
