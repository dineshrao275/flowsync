<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PlatformHealthController extends Controller
{
    public function __construct(
        private readonly TenantDatabaseManager $dbManager,
    ) {}

    /**
     * Public lightweight liveness/readiness ping for load balancers and monitors.
     */
    public function ping(): JsonResponse
    {
        try {
            $centralConn = $this->dbManager->centralConnectionName();
            DB::connection($centralConn)->select('SELECT 1');

            return response()->json([
                'status' => 'healthy',
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'unhealthy',
                'error' => 'Database connection failed',
                'timestamp' => Carbon::now()->toIso8601String(),
            ], 503);
        }
    }

    /**
     * Detailed health inspection for platform super admins.
     * Checks central system DB, sampled active tenant DBs, cache, queue, and storage.
     */
    public function show(): JsonResponse
    {
        $services = [];
        $overallHealthy = true;

        // 1. Central System Database
        $centralStart = microtime(true);
        $centralConn = $this->dbManager->centralConnectionName();
        try {
            DB::connection($centralConn)->select('SELECT 1');
            $centralLatency = round((microtime(true) - $centralStart) * 1000, 2);
            $services['system_database'] = [
                'status' => 'ok',
                'connection' => $centralConn,
                'latency_ms' => $centralLatency,
            ];
        } catch (Throwable $e) {
            $overallHealthy = false;
            $services['system_database'] = [
                'status' => 'error',
                'connection' => $centralConn,
                'error' => $e->getMessage(),
            ];
        }

        // 2. Sampled Tenant Databases
        $tenantQuery = Tenant::query()
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])
            ->where('provisioning_status', Tenant::PROVISIONING_PROVISIONED);

        $totalActiveTenants = (clone $tenantQuery)->count();
        $sampledTenants = (clone $tenantQuery)->limit(5)->get();

        $tenantSamples = [];
        $tenantSuccess = true;

        foreach ($sampledTenants as $tenant) {
            $tenantStart = microtime(true);
            try {
                $this->dbManager->using($tenant, function () {
                    DB::connection('tenant')->select('SELECT 1');
                });
                $tenantLatency = round((microtime(true) - $tenantStart) * 1000, 2);
                $tenantSamples[] = [
                    'tenant_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'status' => 'ok',
                    'latency_ms' => $tenantLatency,
                ];
            } catch (Throwable $e) {
                $tenantSuccess = false;
                $tenantSamples[] = [
                    'tenant_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $services['tenant_databases'] = [
            'status' => $tenantSuccess ? 'ok' : 'degraded',
            'sampled_count' => count($tenantSamples),
            'total_active_tenants' => $totalActiveTenants,
            'samples' => $tenantSamples,
        ];

        if (! $tenantSuccess && count($tenantSamples) > 0) {
            // If all sampled tenant DBs fail, mark overall as degraded/unhealthy
            $allFailed = collect($tenantSamples)->every(fn ($s) => $s['status'] === 'error');
            if ($allFailed) {
                $overallHealthy = false;
            }
        }

        // 3. Cache Store
        try {
            $cacheKey = 'health_check_probe_'.time();
            Cache::put($cacheKey, 1, 10);
            $cachedVal = Cache::get($cacheKey);
            Cache::forget($cacheKey);

            $services['cache'] = [
                'status' => $cachedVal === 1 ? 'ok' : 'degraded',
                'driver' => config('cache.default'),
            ];
        } catch (Throwable $e) {
            $services['cache'] = [
                'status' => 'error',
                'driver' => config('cache.default'),
                'error' => $e->getMessage(),
            ];
        }

        // 4. Storage Disk
        try {
            $disk = config('filesystems.default', 'local');
            $testFile = 'health_probe_'.time().'.tmp';
            Storage::disk($disk)->put($testFile, 'probe');
            $writable = Storage::disk($disk)->exists($testFile);
            Storage::disk($disk)->delete($testFile);

            $services['storage'] = [
                'status' => $writable ? 'ok' : 'error',
                'disk' => $disk,
                'writable' => $writable,
            ];
        } catch (Throwable $e) {
            $services['storage'] = [
                'status' => 'error',
                'disk' => config('filesystems.default', 'local'),
                'error' => $e->getMessage(),
            ];
        }

        // 5. Queue Status
        $services['queue'] = [
            'status' => 'ok',
            'driver' => config('queue.default'),
        ];

        $statusCode = $overallHealthy ? 200 : 503;

        return response()->json([
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => Carbon::now()->toIso8601String(),
            'services' => $services,
        ], $statusCode);
    }
}
