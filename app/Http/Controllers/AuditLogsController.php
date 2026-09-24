<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Read-only platform activity feed combining central `audit_logs` with
 * `impersonation_logs` (rendered as audit-style rows). Both sides are capped to
 * the latest 100 rows before the in-memory sort/paginate keeps queries cheap.
 */
class AuditLogsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['audit', 'impersonation', 'all'])],
            'q' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = new Collection;

        $type = $data['type'] ?? 'all';
        $tenantId = $data['tenant_id'] ?? null;

        if (in_array($type, ['all', 'audit'], true)) {
            $query = AuditLog::with('actor')->latest('id')->limit(100);

            if ($tenantId) {
                $query->where('data->tenant_id', $tenantId);
            }

            $entries = $entries->merge(
                $query->get()->map(fn (AuditLog $log) => $this->auditRow($log))
            );
        }

        if (in_array($type, ['all', 'impersonation'], true)) {
            $query = ImpersonationLog::with(['superAdmin', 'tenant'])->latest('started_at')->limit(100);

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $entries = $entries->merge(
                $query->get()->map(fn (ImpersonationLog $log) => $this->impersonationRow($log))
            );
        }

        if (! empty($data['q'])) {
            $q = $data['q'];
            $entries = $entries->filter(fn (array $row) => str_contains(
                strtolower($row['action'].' '.($row['actor']['name'] ?? '').' '.($row['tenant']['name'] ?? '')),
                strtolower($q)
            ))->values();
        }

        $sorted = $entries->sortByDesc(fn (array $row) => $row['created_at'])->values();

        $perPage = $data['per_page'] ?? 25;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'items' => $paginator->items(),
            'pagination' => $paginator->toArray(),
        ]);
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
            'ip_address' => $log->ip_address,
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
            'actor' => $log->superAdmin?->only(['id', 'name', 'email']),
            'tenant' => $log->tenant?->only(['id', 'name', 'slug']),
            'created_at' => ($log->ended_at ?? $log->started_at)?->toISOString(),
        ];
    }
}
