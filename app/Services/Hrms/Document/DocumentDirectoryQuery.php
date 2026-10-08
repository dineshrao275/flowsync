<?php

namespace App\Services\Hrms\Document;

use App\Enums\Hrms\DocumentStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Document/HRMS — whose documents a listing may contain.
 *
 * Split out because set-level visibility is a different question from the
 * per-record policy: the policy answers “may this caller open this row”, and
 * this answers “which rows may the list contain at all”. The two must agree,
 * so both read the same permissions — directory readers see every
 * non-confidential row (the view scope decides whose: `_all`/legacy/manage
 * the whole tenant, `_assigned` the caller plus their direct reports, `_own`
 * and self-service the caller alone), the sensitive permission adds the
 * confidential rows, and a caller with no employment record sees none.
 */
class DocumentDirectoryQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateFor(User $viewer, array $filters = []): LengthAwarePaginator
    {
        return $this->paginate($this->applyFilters($this->baseFor($viewer), $filters), $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateMine(User $viewer, array $filters = []): LengthAwarePaginator
    {
        $employeeId = Employee::where('user_id', $viewer->id)->value('id');

        // No employment record means no self documents — an empty list, not a
        // 404. A service account with a login and no record must not inherit
        // anyone else’s files.
        if ($employeeId === null) {
            return $this->paginate(EmployeeDocument::query()->whereRaw('1 = 0'), $filters);
        }

        return $this->paginate(
            $this->applyFilters($this->baseFor($viewer)->where('employee_id', $employeeId), $filters),
            $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateExpiring(User $viewer, int $days, array $filters = []): LengthAwarePaginator
    {
        return $this->paginate(
            $this->applyFilters(
                $this->baseFor($viewer)->awaitingAction()->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString()),
                $filters,
            ),
            $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<EmployeeDocument>
     */
    private function baseFor(User $viewer): Builder
    {
        $query = EmployeeDocument::query()->with('type');

        $base = $this->canSeeAll($viewer)
            ? $query
            : $query->whereIn('employee_id', HrmsScope::employeeIdsFor($viewer, 'hrms.documents'));

        // Confidential rows are excluded from every list the caller may not
        // open: a list that names them leaks their existence to someone the
        // policy would refuse at the record.
        return $this->canSeeSensitive($viewer) ? $base : $base->where('confidential', false);
    }

    /**
     * @param  Builder<EmployeeDocument>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<EmployeeDocument>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['document_type_id'])) {
            $query->where('document_type_id', (int) $filters['document_type_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', DocumentStatus::from((string) $filters['status'])->value);
        }

        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(original_name) LIKE ?', [$term]);
            });
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<EmployeeDocument>  $query
     * @param  array<string, mixed>  $filters
     */
    private function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->paginate($perPage);
    }

    private function canSeeAll(User $user): bool
    {
        return HrmsScope::seesAll($user, 'hrms.documents');
    }

    private function canSeeSensitive(User $user): bool
    {
        return $user->hasPermission('hrms.documents.view_sensitive')
            || $user->hasPermission('hrms.documents.manage');
    }
}
