<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SystemErrorBulkRequest;
use App\Http\Requests\SystemErrorIndexRequest;
use App\Http\Requests\SystemErrorNoteRequest;
use App\Http\Requests\SystemErrorUpdateRequest;
use App\Models\ErrorLog;
use App\Models\Tenant;
use App\Services\Errors\ErrorPresenter;
use App\Services\PlatformAudit;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Super Admin application error console (central `error_logs`).
 *
 * Reads are `errors.view`; status/notes are `errors.manage`, enforced by the
 * platform persona map (`config/platform_access.php`). Rows are written by the
 * logging listener — this controller never captures an error itself.
 */
class SystemErrorController extends Controller
{
    public function __construct(private readonly ErrorPresenter $presenter) {}

    public function index(SystemErrorIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $perPage = (int) ($data['per_page'] ?? 25);

        $page = $this->filtered($data)
            ->orderBy($this->sortColumn($data['sort'] ?? 'last_seen'), $data['dir'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($perPage);

        $names = $this->tenantNames($page->getCollection()->pluck('tenant_id')->all());

        return response()->json([
            'items' => $page->getCollection()
                ->map(fn (ErrorLog $log) => $this->presenter->item($log, $names[$log->tenant_id] ?? null))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $statuses = ErrorLog::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $severities = ErrorLog::query()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity');
        $sources = ErrorLog::query()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        $modules = ErrorLog::query()
            ->whereNotNull('module')
            ->selectRaw('module, count(*) as n')
            ->groupBy('module')
            ->orderByDesc('n')
            ->limit(15)
            ->get()
            ->map(fn ($row) => ['module' => $row->module, 'count' => (int) $row->n])
            ->values()
            ->all();

        $count = fn (string $status): int => (int) ($statuses[$status] ?? 0);

        return response()->json([
            'total' => (int) $statuses->sum(),
            'open' => $count(ErrorLog::STATUS_OPEN) + $count(ErrorLog::STATUS_INVESTIGATING),
            'new_24h' => ErrorLog::query()->where('first_seen_at', '>=', now()->subDay())->count(),
            'statuses' => collect(ErrorLog::STATUSES)->mapWithKeys(fn ($s) => [$s => $count($s)])->all(),
            'severities' => collect(ErrorLog::SEVERITIES)->mapWithKeys(fn ($s) => [$s => (int) ($severities[$s] ?? 0)])->all(),
            'sources' => collect(ErrorLog::SOURCES)->mapWithKeys(fn ($s) => [$s => (int) ($sources[$s] ?? 0)])->all(),
            'modules' => $modules,
            'top_recurring' => ErrorLog::query()
                ->whereIn('status', [ErrorLog::STATUS_OPEN, ErrorLog::STATUS_INVESTIGATING])
                ->orderByDesc('occurrences')
                ->limit(5)
                ->get()
                ->map(fn (ErrorLog $log) => $this->presenter->item($log))
                ->values()
                ->all(),
        ]);
    }

    public function show(ErrorLog $errorLog): JsonResponse
    {
        $tenantName = $errorLog->tenant_id !== null
            ? Tenant::query()->whereKey($errorLog->tenant_id)->value('name')
            : null;

        return response()->json([
            'error' => $this->presenter->detail(
                $errorLog,
                $tenantName,
                $errorLog->occurrences()->orderByDesc('occurred_at')->limit(20)->get(),
                $errorLog->notes()->orderByDesc('id')->get(),
                $errorLog->statusHistory()->orderByDesc('id')->limit(50)->get(),
            ),
        ]);
    }

    public function occurrences(Request $request, ErrorLog $errorLog): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = $errorLog->occurrences()->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'items' => $page->getCollection()
                ->map(fn ($occurrence) => $this->presenter->occurrence($occurrence))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function update(SystemErrorUpdateRequest $request, ErrorLog $errorLog, PlatformAudit $audit): JsonResponse
    {
        $data = $request->validated();

        $this->applyStatus($request, $errorLog, $audit, $data['status'], $this->note($data));

        return response()->json([
            'message' => __('Error status updated.'),
            'error' => $this->presenter->item($errorLog->refresh()),
        ]);
    }

    public function bulkUpdate(SystemErrorBulkRequest $request, PlatformAudit $audit): JsonResponse
    {
        $data = $request->validated();
        $note = $this->note($data);

        $logs = ErrorLog::query()->whereIn('id', $data['ids'])->get();
        foreach ($logs as $errorLog) {
            $this->applyStatus($request, $errorLog, $audit, $data['status'], $note);
        }

        return response()->json([
            'message' => __('Errors updated.'),
            'updated' => $logs->count(),
        ]);
    }

    private function applyStatus(Request $request, ErrorLog $errorLog, PlatformAudit $audit, string $to, ?string $note): void
    {
        $from = $errorLog->status;
        $actor = $request->user();

        $errorLog->forceFill([
            'status' => $to,
            'resolved_at' => $to === ErrorLog::STATUS_RESOLVED ? now() : null,
            'resolved_by' => $to === ErrorLog::STATUS_RESOLVED ? $actor?->id : null,
            'ignored_at' => $to === ErrorLog::STATUS_IGNORED ? now() : null,
        ])->save();

        $errorLog->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'note' => $note,
        ]);

        if ($note !== null) {
            $errorLog->notes()->create([
                'author_id' => $actor?->id,
                'author_name' => $actor?->name,
                'body' => $note,
            ]);
        }

        $audit->record($request, 'error.status_changed', 'error_logs', $errorLog->id, [
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'exception_class' => $errorLog->exception_class,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function note(array $data): ?string
    {
        return isset($data['note']) && $data['note'] !== '' ? $data['note'] : null;
    }

    public function storeNote(SystemErrorNoteRequest $request, ErrorLog $errorLog, PlatformAudit $audit): JsonResponse
    {
        $actor = $request->user();

        $note = $errorLog->notes()->create([
            'author_id' => $actor?->id,
            'author_name' => $actor?->name,
            'body' => $request->validated()['body'],
        ]);

        $audit->record($request, 'error.note_added', 'error_logs', $errorLog->id, [
            'note_id' => $note->id,
            'exception_class' => $errorLog->exception_class,
        ]);

        return response()->json([
            'message' => __('Note added.'),
            'note' => $this->presenter->note($note),
        ], 201);
    }

    /** @param  array<string, mixed>  $data */
    private function filtered(array $data): Builder
    {
        return ErrorLog::query()
            ->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))
            ->when(! empty($data['severity']), fn (Builder $q) => $q->where('severity', $data['severity']))
            ->when(! empty($data['source']), fn (Builder $q) => $q->where('source', $data['source']))
            ->when(! empty($data['module']), fn (Builder $q) => $q->where('module', $data['module']))
            ->when(! empty($data['tenant_id']), fn (Builder $q) => $q->where('tenant_id', $data['tenant_id']))
            ->when(! empty($data['q']), fn (Builder $q) => Like::any(
                $q,
                ['message', 'exception_class', 'path', 'file'],
                (string) $data['q'],
            ))
            ->when(! empty($data['from']), fn (Builder $q) => $q->where('last_seen_at', '>=', Carbon::parse($data['from'])->startOfDay()))
            ->when(! empty($data['to']), fn (Builder $q) => $q->where('last_seen_at', '<=', Carbon::parse($data['to'])->endOfDay()));
    }

    private function sortColumn(string $sort): string
    {
        return match ($sort) {
            'first_seen' => 'first_seen_at',
            'occurrences' => 'occurrences',
            default => 'last_seen_at',
        };
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, string>
     */
    private function tenantNames(array $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        /** @var Collection<int, string> $names */
        $names = Tenant::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $names->all();
    }
}
