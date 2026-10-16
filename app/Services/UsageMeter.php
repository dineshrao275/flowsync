<?php

namespace App\Services;

use App\Models\AutomationRun;
use App\Models\IntegrationLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Monthly usage against plan limits: API calls (token requests) and automation runs.
 *
 * A limit lives in the plan's `limits` as `api_calls_per_month` / `automation_runs_per_month`; a
 * missing key means unlimited (existing plans are untouched). Usage is the calendar month of the
 * tenant's own records — `integration_logs` rows and executed `automation_runs` — and is cached for
 * a minute per tenant so a hot API path does not COUNT on every request; the cap is therefore soft
 * by up to a minute of traffic, never by a month.
 */
class UsageMeter
{
    public const API_CALLS = 'api_calls';

    public const AUTOMATION_RUNS = 'automation_runs';

    private const LIMIT_KEYS = [
        self::API_CALLS => 'api_calls_per_month',
        self::AUTOMATION_RUNS => 'automation_runs_per_month',
    ];

    public function __construct(private readonly TenantLimits $limits) {}

    public function limit(string $metric): ?int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return null;
        }

        $value = $this->limits->limit($tenant, self::LIMIT_KEYS[$metric]);

        return is_numeric($value) ? (int) $value : null;
    }

    public function used(string $metric): int
    {
        $tenantId = app(TenantContext::class)->currentId() ?? 0;
        $key = "usage:{$metric}:{$tenantId}:".now()->format('Ym');

        return (int) Cache::remember($key, 60, fn () => $this->count($metric));
    }

    public function exceeded(string $metric): bool
    {
        $limit = $this->limit($metric);

        return $limit !== null && $this->used($metric) >= $limit;
    }

    /** @return array<string, array{used: int, limit: int|null}> */
    public function summary(): array
    {
        $out = [];
        foreach (array_keys(self::LIMIT_KEYS) as $metric) {
            $out[$metric] = ['used' => $this->used($metric), 'limit' => $this->limit($metric)];
        }

        return $out;
    }

    private function count(string $metric): int
    {
        $start = now()->startOfMonth();

        return match ($metric) {
            self::API_CALLS => IntegrationLog::query()->where('created_at', '>=', $start)->count(),
            self::AUTOMATION_RUNS => AutomationRun::query()->where('created_at', '>=', $start)->whereIn('status', ['success', 'failed'])->count(),
            default => 0,
        };
    }

    private function tenant(): ?Tenant
    {
        $id = app(TenantContext::class)->currentId();

        return $id === null ? null : Tenant::query()->find($id);
    }
}
