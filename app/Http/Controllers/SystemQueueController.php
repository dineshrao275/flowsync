<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Super-admin view of the background queue: how much is waiting, how old the oldest job is, and the
 * failed jobs with retry / discard. Reads the central database (`jobs`, `failed_jobs`); tenant context
 * rides inside each job payload, so retrying one re-queues it exactly as it failed.
 */
class SystemQueueController extends Controller
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    public function show(): JsonResponse
    {
        $db = $this->db();

        $oldest = $db->table('jobs')->min('created_at');
        $failed = $db->table('failed_jobs')->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'queue' => [
                'pending' => $db->table('jobs')->whereNull('reserved_at')->count(),
                'reserved' => $db->table('jobs')->whereNotNull('reserved_at')->count(),
                'oldest_age_seconds' => $oldest ? max(0, now()->timestamp - (int) $oldest) : null,
                'failed' => $db->table('failed_jobs')->count(),
                'by_queue' => $db->table('jobs')->select('queue', DB::raw('count(*) as total'))->groupBy('queue')->pluck('total', 'queue'),
            ],
            'failed_jobs' => $failed->map(fn ($row) => $this->presentFailed($row))->values(),
        ]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $db = $this->db();
        $row = $db->table('failed_jobs')->where('id', $id)->first();

        abort_if($row === null, 404);

        Queue::connection($row->connection)->pushRaw($row->payload, $row->queue);
        $db->table('failed_jobs')->where('id', $id)->delete();

        return response()->json(['message' => __('Job queued again.')]);
    }

    public function forget(int $id): JsonResponse
    {
        $deleted = $this->db()->table('failed_jobs')->where('id', $id)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['message' => __('Failed job discarded.')]);
    }

    private function db()
    {
        return DB::connection($this->dbm->centralConnectionName());
    }

    /** @return array<string, mixed> */
    private function presentFailed(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];

        return [
            'id' => $row->id,
            'queue' => $row->queue,
            'job' => $payload['displayName'] ?? ($payload['job'] ?? 'unknown'),
            'tenant_id' => $payload['tenant_id'] ?? null,
            'request_id' => $payload['request_id'] ?? null,
            'failed_at' => $row->failed_at ? Carbon::parse($row->failed_at)->toIso8601String() : null,
            // First line only: a full trace can quote data from the job.
            'error' => mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 300),
        ];
    }
}
