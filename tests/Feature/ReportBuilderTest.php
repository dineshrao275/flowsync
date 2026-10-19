<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportBuilderController;
use App\Mail\ScheduledReportMail;
use App\Models\SavedReport;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F11.9 — whitelisted datasets, the owner's row scope, CSV export that cannot run formulas, scheduled email. */
class ReportBuilderTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_catalog_validation_and_preview(): void
    {
        $this->login();
        $this->getJson('/api/report-builder')->assertOk()->assertJsonPath('catalog.tasks.available', true);

        $this->postJson('/api/report-builder/preview', ['dataset' => 'tasks', 'columns' => ['key', 'secret_column']])->assertOk()->assertJsonPath('columns.key', 'Key');
        $this->postJson('/api/report-builder/preview', ['dataset' => 'tasks', 'columns' => ['nope']])->assertStatus(422);
        $this->postJson('/api/report-builder/preview', ['dataset' => 'users', 'columns' => ['email']])->assertStatus(422);
    }

    public function test_saved_reports_are_private_and_export_neutralises_formulas(): void
    {
        $this->login();
        $id = $this->postJson('/api/report-builder', ['name' => 'My tasks', 'dataset' => 'tasks', 'columns' => ['key', 'title'], 'schedule' => 'daily'])->assertCreated()->json('report.id');

        $this->assertSame("'=1+1", trim(explode("\n", ReportBuilderController::csv(['t' => 'T'], [['t' => '=1+1']]))[1]));

        $this->postJson('/api/auth/logout');
        $this->login('editor@flowsync.test');
        $this->getJson("/api/report-builder/$id/export")->assertNotFound();
        $this->getJson('/api/report-builder')->assertJsonCount(0, 'reports');
    }

    public function test_scheduled_reports_are_mailed_once_per_window(): void
    {
        Mail::fake();
        $this->connectTenant('acme');
        $owner = User::where('email', 'admin@flowsync.test')->firstOrFail();
        SavedReport::create(['user_id' => $owner->id, 'name' => 'Daily', 'dataset' => 'tasks', 'columns' => ['key'], 'filters' => [], 'schedule' => 'daily']);

        $this->artisan('reports:send-scheduled', ['--all' => true])->assertSuccessful();
        $this->artisan('reports:send-scheduled', ['--all' => true])->assertSuccessful();

        Mail::assertQueued(ScheduledReportMail::class, 1);
    }
}
