<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * F11.2 — Prometheus-format platform metrics for a scraper. Not a session route: it answers only to
 * `Authorization: Bearer <METRICS_TOKEN>` (constant-time compare) and is a 404 when no token is configured,
 * so it does not exist on a deployment that has not opted in. Central DB only — no tenant data is opened.
 */
class MetricsController extends Controller
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    public function __invoke(Request $request): Response
    {
        $token = (string) config('observability.metrics_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $db = DB::connection($this->dbm->centralConnectionName());
        $lines = [];
        $emit = function (string $name, string $help, $value, array $labels = []) use (&$lines): void {
            static $seen = [];
            if (! isset($seen[$name])) {
                $lines[] = "# HELP $name $help";
                $lines[] = "# TYPE $name gauge";
                $seen[$name] = true;
            }
            $l = $labels ? '{'.collect($labels)->map(fn ($v, $k) => $k.'="'.addcslashes((string) $v, "\"\\\n").'"')->implode(',').'}' : '';
            $lines[] = "$name$l ".(float) $value;
        };

        $oldest = $db->table('jobs')->min('created_at');
        $emit('flowsync_queue_pending', 'Jobs waiting to run.', $db->table('jobs')->whereNull('reserved_at')->count());
        $emit('flowsync_queue_reserved', 'Jobs a worker is running.', $db->table('jobs')->whereNotNull('reserved_at')->count());
        $emit('flowsync_queue_failed', 'Failed jobs awaiting retry or discard.', $db->table('failed_jobs')->count());
        $emit('flowsync_queue_oldest_age_seconds', 'Age of the oldest waiting job.', $oldest ? max(0, now()->timestamp - (int) $oldest) : 0);

        foreach (Tenant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $emit('flowsync_tenants', 'Tenants by lifecycle status.', $total, ['status' => $status]);
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
