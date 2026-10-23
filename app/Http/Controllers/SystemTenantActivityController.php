<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\UsageMetric;
use Illuminate\Http\JsonResponse;

/**
 * Platform view of adoption (F11.10): for each serviceable tenant the latest recorded active-user and activity
 * numbers (written daily by `tenants:collect-usage`), with dormant tenants — active or trialling, older than two
 * weeks, nobody active in 30 days — flagged first. Reads the central metrics table only; no tenant database is opened.
 */
class SystemTenantActivityController extends Controller
{
    private const KEYS = ['users', 'active_users_7d', 'active_users_30d', 'activity_30d'];

    public function __invoke(): JsonResponse
    {
        $tenants = Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->orderBy('name')->limit(500)->get(['id', 'name', 'slug', 'status', 'created_at']);

        $latest = UsageMetric::query()->whereIn('tenant_id', $tenants->pluck('id'))->whereIn('metric_key', self::KEYS)
            ->orderBy('period')->get()->groupBy('tenant_id')->map(fn ($rows) => $rows->groupBy('metric_key')->map(fn ($g) => ['value' => (int) $g->last()->value, 'period' => (string) $g->last()->period]));

        $rows = $tenants->map(function (Tenant $t) use ($latest) {
            $m = $latest[$t->id] ?? collect();
            $collected = $m->has('active_users_30d');
            $active30 = $m['active_users_30d']['value'] ?? null;

            return [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status,
                'users' => $m['users']['value'] ?? null,
                'active_7d' => $m['active_users_7d']['value'] ?? null,
                'active_30d' => $active30,
                'activity_30d' => $m['activity_30d']['value'] ?? null,
                'as_of' => $m['active_users_30d']['period'] ?? null,
                'dormant' => $collected && $active30 === 0 && $t->created_at->lt(now()->subDays(14)),
            ];
        })->sortByDesc('dormant')->values();

        return response()->json([
            'tenants' => $rows,
            'summary' => [
                'tenants' => $rows->count(),
                'with_data' => $rows->whereNotNull('active_30d')->count(),
                'dormant' => $rows->where('dormant', true)->count(),
                'active_users_30d' => (int) $rows->sum('active_30d'),
            ],
        ]);
    }
}
