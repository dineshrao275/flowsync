<?php

namespace App\Services\Hrms\Audit;

use App\Models\Hrms\Shared\HrmsAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Audit/HRMS — reading the append-only trail.
 *
 * The ledger is write-once (`HrmsAuditLogger`), so this owns the only read
 * paths: a filtered index for the viewer and a single record's history for
 * the trail endpoint and the profile tab. Exact matches for the structured
 * filters (actor, subject, action), `LIKE` only for the free-text `q` —
 * mixing the two would make a bookmarked filter silently fuzzy.
 */
class AuditLogQuery
{
    private const SORTABLE = [
        'id' => 'hrms_audit_logs.id',
        'created_at' => 'hrms_audit_logs.created_at',
        'action' => 'hrms_audit_logs.action',
    ];

    private const PER_PAGE = 25;

    private const TRAIL_LIMIT = 200;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<HrmsAuditLog>
     */
    public function build(array $filters): Builder
    {
        $query = HrmsAuditLog::query();

        if (isset($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', (string) $filters['subject_type']);
        }

        if (isset($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['q'])) {
            $term = '%'.(string) $filters['q'].'%';
            $query->where(fn (Builder $nested): Builder => $nested
                ->where('action', 'like', $term)
                ->orWhere('subject_type', 'like', $term));
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        [$column, $direction] = $this->sort($filters);

        return $this->build($filters)
            ->with('actor:id,name')
            ->orderBy($column, $direction)
            ->orderBy('hrms_audit_logs.id', $direction)
            ->paginate($this->perPage($filters));
    }

    /**
     * One record's full before/after history, newest first.
     *
     * @return Collection<int, HrmsAuditLog>
     */
    public function trail(string $subjectType, int $subjectId): Collection
    {
        return HrmsAuditLog::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit(self::TRAIL_LIMIT)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{string, string}
     */
    private function sort(array $filters): array
    {
        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? self::SORTABLE['id'];
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? self::PER_PAGE)));
    }
}
