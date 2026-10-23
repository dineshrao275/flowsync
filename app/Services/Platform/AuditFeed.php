<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The platform activity feed: a SQL union of central `audit_logs` and
 * `impersonation_logs`, ordered `event_at desc, src asc, id desc` (see
 * AuditLogsController for why `src` precedes `id`), and the presenter that turns
 * a page of `(id, src)` rows back into audit-style rows.
 */
class AuditFeed
{
    /**
     * The filtered, ordered union of both sources — a query, not a result, so
     * `index` paginates in SQL and `export` streams without a memory copy.
     *
     * @param  array<string, mixed>  $data
     */
    public function orderedEvents(array $data): QueryBuilder
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
    public function hydrate(Collection $rows): Collection
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
