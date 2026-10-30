<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Like;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One timeline over the tenant's two audit ledgers: `activities` (TMS and account events) and
 * `hrms_audit_logs` (HR decisions). Read-only; the ledgers stay separate (the HR one is append-only and
 * masked at the writer) — this only merges them for reading. Platform audit stays in the super-admin console.
 */
class UnifiedAuditController extends Controller
{
    private const EXPORT_CAP = 10000;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->query($filters)->paginate(min((int) $request->integer('per_page', 25), 100));
        $actors = User::query()->whereIn('id', collect($page->items())->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'entries' => collect($page->items())->map(fn ($r) => $this->present($r, $actors))->values(),
            'sources' => $this->sources(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)->limit(self::EXPORT_CAP)->get();
        $actors = User::query()->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return response()->streamDownload(function () use ($rows, $actors): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['when', 'source', 'actor', 'action', 'subject_type', 'subject_id', 'ip_address', 'data']);
            foreach ($rows as $r) {
                $e = $this->present($r, $actors);
                fputcsv($out, [$e['created_at'], $e['source'], $e['actor'], $e['action'], $e['subject_type'], $e['subject_id'], $e['ip_address'], json_encode($e['data'])]);
            }
            fclose($out);
        }, 'audit-timeline.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'source' => ['nullable', 'in:activity,hrms'],
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
    }

    /** @return list<string> */
    private function sources(): array
    {
        return array_values(array_filter(['activity', Schema::hasTable('hrms_audit_logs') ? 'hrms' : null]));
    }

    private function leg(string $table, string $source): Builder
    {
        return DB::table($table)->selectRaw('? as source, id, actor_user_id, subject_type, subject_id, action, data, ip_address, created_at', [$source]);
    }

    /** @param array<string, mixed> $f */
    private function query(array $f): Builder
    {
        $legs = [];
        if (($f['source'] ?? 'activity') !== 'hrms') {
            $legs[] = $this->leg('activities', 'activity');
        }
        if (($f['source'] ?? 'hrms') !== 'activity' && Schema::hasTable('hrms_audit_logs')) {
            $legs[] = $this->leg('hrms_audit_logs', 'hrms');
        }
        $legs = $legs ?: [$this->leg('activities', 'activity')];

        $union = array_shift($legs);
        foreach ($legs as $leg) {
            $union->unionAll($leg);
        }

        $query = DB::query()->fromSub($union, 'u')->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($f['actor_id'])) {
            $query->where('actor_user_id', (int) $f['actor_id']);
        }
        if (! empty($f['action'])) {
            Like::any($query, ['action'], $f['action']);
        }
        if (! empty($f['q'])) {
            Like::any($query, ['action', 'subject_type'], $f['q']);
        }
        if (! empty($f['from'])) {
            $query->where('created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $query->where('created_at', '<=', $f['to'].' 23:59:59');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(object $r, $actors): array
    {
        return [
            'source' => $r->source,
            'id' => (int) $r->id,
            'actor' => $r->actor_user_id ? ($actors[$r->actor_user_id] ?? '#'.$r->actor_user_id) : null,
            'actor_user_id' => $r->actor_user_id ? (int) $r->actor_user_id : null,
            'action' => $r->action,
            'subject_type' => class_basename((string) $r->subject_type),
            'subject_id' => (int) $r->subject_id,
            'ip_address' => $r->ip_address,
            'data' => is_string($r->data) ? json_decode($r->data, true) : $r->data,
            'created_at' => $r->created_at,
        ];
    }
}
