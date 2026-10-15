<?php

namespace App\Services\Hrms\Audit;

use App\Models\Hrms\Shared\HrmsDataAccessLog;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Audit/HRMS — reading the who-read-what ledger.
 *
 * The sibling of {@see AuditLogQuery} for `hrms_data_access_logs`: every
 * salary, bank, statutory and document read plus every export, with the
 * field NAMES that travelled (values never reach this table — that is the
 * writer's contract, pinned by the API test). Same filter vocabulary as
 * the audit trail, minus the subject pair (this ledger names its subject
 * as `model` + `record_id` instead).
 */
class DataAccessQuery
{
    private const SORTABLE = [
        'id' => 'hrms_data_access_logs.id',
        'created_at' => 'hrms_data_access_logs.created_at',
        'action' => 'hrms_data_access_logs.action',
    ];

    private const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<HrmsDataAccessLog>
     */
    public function build(array $filters): Builder
    {
        $query = HrmsDataAccessLog::query();

        if (isset($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['model'])) {
            $query->where('model', (string) $filters['model']);
        }

        if (isset($filters['record_id'])) {
            $query->where('record_id', (int) $filters['record_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action'] instanceof BackedEnum
                ? $filters['action']->value
                : (string) $filters['action']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['q'])) {
            $term = '%'.(string) $filters['q'].'%';
            $query->where('model', 'like', $term);
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
            ->orderBy('hrms_data_access_logs.id', $direction)
            ->paginate($this->perPage($filters));
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
