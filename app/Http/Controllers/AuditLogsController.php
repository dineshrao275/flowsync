<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use App\Models\Tenant;
use App\Services\PlatformAudit;
use App\Services\Security\AuditChain;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only platform activity feed combining central `audit_logs` with
 * `impersonation_logs` (rendered as audit-style rows).
 *
 * Pagination happens in SQL over the union of the two sources (H-7), not over
 * an in-memory merge of the latest 100 rows each: the old cap silently hid
 * everything older than 100 rows per source, so the feed's `total` and its
 * page count were both wrong on any busy platform. Ordering is
 * `event_at desc, src asc, id desc` — `src` before `id` because ids from two
 * tables are not comparable, and it keeps `audit` rows ahead of
 * `impersonation` rows on a second-precision tie.
 */
class AuditLogsController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    /**
     * @return array<string, list<mixed>>
     */
    private function filters(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['audit', 'impersonation', 'all'])],
            'q' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate($this->filters() + [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $data['per_page'] ?? 25;
        $page = max(1, (int) $request->query('page', 1));

        $events = $this->orderedEvents($data);
        $total = (clone $events)->count();

        $rows = $events
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $items = $this->hydrate($rows);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Stream the same filtered feed as CSV, capped at {@see EXPORT_LIMIT} rows.
     *
     * The export itself is an audited action: it writes an `audit.exported`
     * row (who exported what scope) before the first byte is streamed, so a
     * bulk read of the platform trail is answerable in the trail.
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate($this->filters());

        $rows = $this->orderedEvents($data)->limit(self::EXPORT_LIMIT)->get();

        $items = $this->hydrate($rows);
        $head = app(AuditChain::class)->head();

        app(PlatformAudit::class)->record(
            $request,
            'audit.exported',
            null,
            null,
            [
                'type' => $data['type'] ?? 'all',
                'q' => $data['q'] ?? null,
                'tenant_id' => $data['tenant_id'] ?? null,
                'rows' => $items->count(),
                // Pinning this lets a later reader prove nothing before it was altered (P8.6).
                'chain_head' => $head,
            ],
        );

        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'created_at', 'type', 'action', 'actor', 'actor_email',
                'subject_type', 'subject_id', 'ip_address', 'tenant', 'data', 'hash', 'prev_hash',
            ]);

            foreach ($items as $row) {
                fputcsv($out, [
                    $row['created_at'],
                    $row['type'],
                    $row['action'],
                    $row['actor']['name'] ?? '',
                    $row['actor']['email'] ?? '',
                    $row['subject_type'] ?? '',
                    $row['subject_id'] ?? '',
                    $row['ip_address'] ?? '',
                    $row['tenant']['name'] ?? '',
                    json_encode($row['data']),
                    $row['hash'] ?? '',
                    $row['prev_hash'] ?? '',
                ]);
            }

            fclose($out);
        }, 'audit-logs-'.now()->format('Y-m-d-Hi').'.csv', [
            'Content-Type' => 'text/csv',
            'X-Audit-Chain-Head' => $head ?? '',
        ]);
    }

    /** Walk the hash chain; the same answer `php artisan audit:verify` gives. */
    public function verifyChain(): JsonResponse
    {
        return response()->json(app(AuditChain::class)->verify());
    }

    /**
     * The filtered, ordered union of both sources — a query, not a result, so
     * `index` paginates in SQL and `export` streams without a memory copy.
     *
     * @param  array<string, mixed>  $data
     */
    private function orderedEvents(array $data): QueryBuilder
    {
        $type = $data['type'] ?? 'all';
        $q = isset($data['q']) ? mb_strtolower($data['q']) : null;
        $tenantId = $data['tenant_id'] ?? null;
        $like = $q !== null ? '%'.$q.'%' : null;

        $connection = DB::connection((new AuditLog)->getConnectionName());

        $subs = [];

        if (in_array($type, ['all', 'audit'], true)) {
            $audit = $connection->table('audit_logs')
                ->select('id')
                ->selectRaw('created_at as event_at')
                ->selectRaw("'audit' as src");

            if ($like !== null) {
                $audit->where(function (QueryBuilder $w) use ($like): void {
                    $w->whereRaw('lower(audit_logs.action) like ?', [$like])
                        ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                            ->from('users')
                            ->whereColumn('users.id', 'audit_logs.actor_id')
                            ->whereRaw('lower(users.name) like ?', [$like]));
                });
            }

            if ($tenantId !== null) {
                $audit->where(function (QueryBuilder $w) use ($tenantId): void {
                    $w->whereRaw("cast(audit_logs.data->>'tenant_id' as text) = ?", [(string) $tenantId])
                        ->orWhere(function (QueryBuilder $w2) use ($tenantId): void {
                            $w2->whereIn('audit_logs.subject_type', ['tenants', Tenant::class])
                                ->where('audit_logs.subject_id', $tenantId);
                        });
                });
            }

            $subs[] = $audit;
        }

        if (in_array($type, ['all', 'impersonation'], true)) {
            $impersonation = $connection->table('impersonation_logs')
                ->select('id')
                ->selectRaw('coalesce(ended_at, started_at) as event_at')
                ->selectRaw("'impersonation' as src");

            if ($like !== null) {
                $impersonation->where(function (QueryBuilder $w) use ($like): void {
                    $w->whereRaw(
                        "lower(case when ended_at is null then 'impersonation.started' else 'impersonation.ended' end) like ?",
                        [$like]
                    )
                        ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                            ->from('users')
                            ->whereColumn('users.id', 'impersonation_logs.super_admin_id')
                            ->whereRaw('lower(users.name) like ?', [$like]))
                        ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                            ->from('tenants')
                            ->whereColumn('tenants.id', 'impersonation_logs.tenant_id')
                            ->whereRaw('lower(tenants.name) like ?', [$like]));
                });
            }

            if ($tenantId !== null) {
                $impersonation->where('impersonation_logs.tenant_id', $tenantId);
            }

            $subs[] = $impersonation;
        }

        $union = array_shift($subs);

        foreach ($subs as $sub) {
            $union->unionAll($sub);
        }

        return $connection->query()
            ->fromSub($union, 'events')
            ->orderByDesc('event_at')
            ->orderBy('src')
            ->orderByDesc('id');
    }

    /**
     * Turn the page's `(id, src)` rows back into presentable audit rows,
     * preserving the SQL order.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function hydrate(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }

        $audits = AuditLog::with('actor')
            ->whereIn('id', $rows->where('src', 'audit')->pluck('id'))
            ->get()
            ->keyBy('id');

        $impersonations = ImpersonationLog::with(['superAdmin', 'tenant'])
            ->whereIn('id', $rows->where('src', 'impersonation')->pluck('id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function (object $row) use ($audits, $impersonations): ?array {
            return $row->src === 'audit'
                ? ($audits->has($row->id) ? $this->auditRow($audits->get($row->id)) : null)
                : ($impersonations->has($row->id) ? $this->impersonationRow($impersonations->get($row->id)) : null);
        })->filter()->values();
    }

    private function auditRow(AuditLog $log): array
    {
        return [
            'id' => 'a'.$log->id,
            'type' => 'audit',
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'data' => $log->data,
            'hash' => $log->hash,
            'prev_hash' => $log->prev_hash,
            'ip_address' => $log->ip_address,
            'request_id' => $log->request_id,
            'actor' => $log->actor?->only(['id', 'name', 'email']),
            'tenant' => null,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }

    private function impersonationRow(ImpersonationLog $log): array
    {
        return [
            'id' => 'i'.$log->id,
            'type' => 'impersonation',
            'action' => $log->ended_at ? 'impersonation.ended' : 'impersonation.started',
            'subject_type' => 'users',
            'subject_id' => null,
            'data' => ['impersonated_user_id' => $log->impersonated_user_id],
            'ip_address' => $log->ip_address,
            'request_id' => null,
            'actor' => $log->superAdmin?->only(['id', 'name', 'email']),
            'tenant' => $log->tenant?->only(['id', 'name', 'slug']),
            'created_at' => ($log->ended_at ?? $log->started_at)?->toISOString(),
        ];
    }
}
