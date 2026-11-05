<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\ErrorOccurrence;
use App\Services\Errors\ErrorRecorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Super Admin application error console: capture, rollup, triage, retention. */
class SystemErrorsTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('error_logging.enabled', true);
    }

    private function superAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    /** @param  array<string, mixed>  $overrides */
    private function error(array $overrides = []): ErrorLog
    {
        return ErrorLog::create(array_merge([
            'fingerprint' => hash('sha256', uniqid('err', true)),
            'exception_class' => RuntimeException::class,
            'message' => 'Something exploded',
            'severity' => 'error',
            'status' => ErrorLog::STATUS_OPEN,
            'module' => 'tms',
            'source' => 'backend',
            'environment' => 'testing',
            'occurrences' => 1,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(5),
        ], $overrides));
    }

    public function test_an_uncaught_exception_is_captured_once_in_the_central_store(): void
    {
        Route::middleware(['web'])->get('/api/_probe/boom', fn () => throw new RuntimeException('Probe detonated'));

        $this->getJson('/api/_probe/boom')->assertStatus(500);

        $this->assertSame(1, ErrorLog::count());
        $log = ErrorLog::firstOrFail();
        $this->assertSame('backend', $log->source);
        $this->assertSame(ErrorLog::STATUS_OPEN, $log->status);
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertStringContainsString('Probe detonated', $log->message);
        $this->assertSame(1, $log->occurrences);
        $this->assertNotNull($log->fingerprint);
        $this->assertSame(1, ErrorOccurrence::count());
    }

    public function test_repeated_events_roll_up_while_empty_levels_are_ignored(): void
    {
        $recorder = app(ErrorRecorder::class);

        $recorder->recordMessage('error', 'Database cluster unavailable', ['path' => 'api/tasks']);
        $recorder->recordMessage('error', 'Database cluster unavailable', ['path' => 'api/tasks']);
        $recorder->recordMessage('info', 'Just noise');
        $recorder->recordMessage('debug', 'Also noise');

        $this->assertSame(1, ErrorLog::count());
        $log = ErrorLog::firstOrFail();
        $this->assertSame(2, $log->occurrences);
        $this->assertSame('tms', $log->module);
        $this->assertSame(2, ErrorOccurrence::count());
    }

    public function test_a_marked_log_line_is_not_captured_again(): void
    {
        Log::error('Mirrored line', ['error_capture' => false]);

        $this->assertSame(0, ErrorLog::count());
    }

    public function test_a_frontend_beacon_is_stored_once_with_the_frontend_source(): void
    {
        $this->postJson('/api/logs/client', [
            'level' => 'error',
            'message' => 'Boundary crash',
            'context' => ['componentStack' => 'at TaskDetail'],
            'url' => '/app/projects/1',
        ])->assertOk();

        $this->assertSame(1, ErrorLog::count());
        $log = ErrorLog::firstOrFail();
        $this->assertSame('frontend', $log->source);
        $this->assertStringContainsString('Boundary crash', $log->message);
        $this->assertSame('at TaskDetail', $log->context['componentStack'] ?? null);
    }

    public function test_messages_are_redacted_before_storage(): void
    {
        app(ErrorRecorder::class)->recordMessage(
            'error',
            'Call failed with token=super-secret for bob@example.com',
        );

        $message = ErrorLog::firstOrFail()->message;
        $this->assertStringNotContainsString('super-secret', $message);
        $this->assertStringNotContainsString('bob@example.com', $message);
    }

    public function test_the_list_and_summary_answer_with_filters(): void
    {
        $open = $this->error(['message' => 'Open one']);
        $this->error(['message' => 'Resolved one', 'status' => ErrorLog::STATUS_RESOLVED, 'severity' => 'critical', 'module' => 'hrms']);
        $this->error(['message' => 'Ignored one', 'status' => ErrorLog::STATUS_IGNORED, 'source' => 'queue']);

        $this->superAdmin();

        $this->getJson('/api/system/errors?status=open')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', $open->id);

        $this->getJson('/api/system/errors?q=Resolved')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.message', 'Resolved one');

        $this->getJson('/api/system/errors?module=hrms&severity=critical')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/system/errors?source=queue')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/system/errors/summary')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('open', 1)
            ->assertJsonPath('statuses.resolved', 1)
            ->assertJsonPath('severities.critical', 1)
            ->assertJsonPath('sources.queue', 1);
    }

    public function test_resolving_is_audited_and_a_new_event_marks_it_recurred(): void
    {
        $recorder = app(ErrorRecorder::class);
        $recorder->recordMessage('error', 'Scheduled job exploded', ['path' => 'api/tasks']);
        $log = ErrorLog::firstOrFail();

        $this->superAdmin();

        $this->putJson("/api/system/errors/{$log->id}", [
            'status' => ErrorLog::STATUS_RESOLVED,
            'note' => 'Patched the null guard',
        ])->assertOk()->assertJsonPath('error.status', ErrorLog::STATUS_RESOLVED);

        $log->refresh();
        $this->assertNotNull($log->resolved_at);
        $this->assertSame(1, $log->statusHistory()->count());
        $this->assertSame(1, $log->notes()->count());
        $this->assertDatabaseHas('error_log_status_history', [
            'error_log_id' => $log->id, 'from_status' => ErrorLog::STATUS_OPEN, 'to_status' => ErrorLog::STATUS_RESOLVED,
        ], 'iso_system');
        $this->assertSame(1, DB::connection('iso_system')->table('audit_logs')->where('action', 'error.status_changed')->count());

        $this->travel(2)->minutes();
        $recorder->recordMessage('error', 'Scheduled job exploded', ['path' => 'api/tasks']);

        $this->getJson("/api/system/errors/{$log->id}")
            ->assertOk()
            ->assertJsonPath('error.recurred', true)
            ->assertJsonPath('error.status', ErrorLog::STATUS_RESOLVED)
            ->assertJsonPath('error.occurrences', 2);
    }

    public function test_bulk_status_update_marks_every_selected_row(): void
    {
        $first = $this->error(['message' => 'First']);
        $second = $this->error(['message' => 'Second']);
        $untouched = $this->error(['message' => 'Third']);

        $this->superAdmin();

        $this->postJson('/api/system/errors/bulk', [
            'ids' => [$first->id, $second->id],
            'status' => ErrorLog::STATUS_RESOLVED,
            'note' => 'Swept in one pass',
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame(ErrorLog::STATUS_RESOLVED, $first->refresh()->status);
        $this->assertSame(ErrorLog::STATUS_RESOLVED, $second->refresh()->status);
        $this->assertSame(ErrorLog::STATUS_OPEN, $untouched->refresh()->status);
        $this->assertNotNull($first->resolved_at);
        $this->assertSame(2, $first->statusHistory()->count() + $second->statusHistory()->count());
        $this->assertSame(2, $first->notes()->count() + $second->notes()->count());
        $this->assertSame(2, DB::connection('iso_system')->table('audit_logs')->where('action', 'error.status_changed')->count());
    }

    public function test_notes_can_be_added_without_changing_status(): void
    {
        $log = $this->error();
        $this->superAdmin();

        $this->postJson("/api/system/errors/{$log->id}/notes", ['body' => 'Reproduced on staging'])
            ->assertCreated()
            ->assertJsonPath('note.body', 'Reproduced on staging');

        $this->assertSame(1, $log->notes()->count());
        $this->assertSame(1, DB::connection('iso_system')->table('audit_logs')->where('action', 'error.note_added')->count());

        $this->getJson("/api/system/errors/{$log->id}/occurrences")
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_prune_removes_old_occurrences_and_stale_rollups_only(): void
    {
        $fresh = $this->error(['message' => 'Recent']);
        $stale = $this->error(['message' => 'Ancient', 'first_seen_at' => now()->subDays(120), 'last_seen_at' => now()->subDays(120)]);

        ErrorOccurrence::create(['error_log_id' => $fresh->id, 'occurred_at' => now()->subDays(120)]);
        ErrorOccurrence::create(['error_log_id' => $fresh->id, 'occurred_at' => now()]);

        $this->artisan('errors:prune')->assertSuccessful();

        $this->assertNull(ErrorLog::find($stale->id));
        $this->assertNotNull(ErrorLog::find($fresh->id));
        $this->assertSame(1, ErrorOccurrence::where('error_log_id', $fresh->id)->count());
    }
}
