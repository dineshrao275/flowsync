<?php

namespace App\Http\Controllers;

use App\Jobs\BuildTenantExportJob;
use App\Models\ExportRun;
use App\Models\Tenant;
use App\Services\ExportService;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 5 — tenant full data export.
 *
 * Routes (in the auth → tenant group, gated by ensure_module:export.full):
 *
 *   POST  api/my-export            → queue a new export run
 *   GET   api/my-export            → list the tenant's last 5 runs + status
 *   GET   api/my-export/{run}      → single run status
 *
 * Signed download (outside auth, like attachments):
 *   GET   api/exports/{run}/download → stream the ZIP
 *
 * The download route sits outside the auth/tenant middleware groups for the
 * same reason as `attachments.download`: it is opened in a fresh browser tab
 * without a session. The tenant id travels inside the signed URL, and the
 * actual lookup is done inside TenantDatabaseManager::using().
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Queue a new full-data export for this tenant.
     *
     * Restricted to tenant admins — a full data export is a privileged
     * operation that extracts all tenant data into a downloadable archive.
     *
     * Accepts an optional `categories` array; defaults to all supported
     * categories when absent. Returns the queued run with a `poll_url` the
     * SPA can use to check status.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasRole('admin'),
            403,
            'Only tenant admins can initiate a data export.',
        );

        $data = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', ExportService::CATEGORIES)],
        ]);

        $categories = $data['categories'] ?? ExportService::CATEGORIES;

        $tenantId = $this->tenantContext->currentId();
        abort_unless($tenantId, 404);

        $run = ExportRun::create([
            'user_id' => $request->user()?->id,
            'categories' => $categories,
            'status' => ExportRun::STATUS_PENDING,
        ]);

        BuildTenantExportJob::dispatch($tenantId, $run->id);

        return response()->json([
            'message' => 'Export queued. Poll the status URL to track progress.',
            'run' => $this->presentRun($run),
        ], 202);
    }

    /**
     * List the most recent export runs initiated by the authenticated user
     * (latest 5). Each tenant user only sees their own export history.
     */
    public function index(Request $request): JsonResponse
    {
        $runs = ExportRun::where('user_id', $request->user()?->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            'runs' => $runs->map(fn ($r) => $this->presentRun($r)),
        ]);
    }

    /**
     * Single run status + (if ready) a fresh signed download URL.
     *
     * Ownership is verified: a user can only poll runs they initiated.
     */
    public function show(Request $request, int $runId): JsonResponse
    {
        $run = ExportRun::where('user_id', $request->user()?->id)
            ->findOrFail($runId);

        return response()->json(['run' => $this->presentRun($run)]);
    }

    /**
     * Stream the export ZIP — intentionally outside auth/tenant middleware
     * groups (opened in a fresh browser tab; the tenant id + reader id ride
     * inside the signed URL as the security bearer).
     *
     * @param  int  $run  raw integer (no model binding — the default connection
     *                    is central at this point; the lookup happens inside using())
     */
    public function download(Request $request, int $run): StreamedResponse
    {
        $tenant = Tenant::find((int) $request->query('tenant'));
        abort_if($tenant === null, 404, 'Tenant not found.');

        return app(TenantDatabaseManager::class)->using($tenant, function () use ($run) {
            $record = ExportRun::find($run);

            abort_if($record === null, 404, 'Export run not found.');
            abort_if($record->status !== ExportRun::STATUS_READY, 404, 'Export is not ready yet.');
            abort_if($record->isExpired(), 410, 'This export link has expired. Please request a new export.');

            $fullPath = storage_path('app/private/'.$record->file_path);
            abort_if(! file_exists($fullPath), 404, 'Export file no longer exists.');

            $filename = 'flowsync-export-'.now()->format('Ymd-His').'.zip';

            return response()->streamDownload(function () use ($fullPath) {
                $handle = fopen($fullPath, 'rb');
                while (! feof($handle)) {
                    echo fread($handle, 65536);
                    flush();
                }
                fclose($handle);
            }, $filename, [
                'Content-Type' => 'application/zip',
                'Content-Length' => filesize($fullPath),
            ]);
        });
    }

    // ------------------------------------------------------------------ helpers

    private function presentRun(ExportRun $run): array
    {
        $tenantId = $this->tenantContext->currentId();

        $downloadUrl = null;
        if ($run->isReady() && ! $run->isExpired() && $tenantId) {
            $downloadUrl = url()->temporarySignedRoute(
                'exports.download',
                $run->expires_at ?? now()->addHour(),
                ['run' => $run->id, 'tenant' => $tenantId],
            );
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'categories' => $run->categories,
            'file_size' => $run->file_size,
            'expires_at' => $run->expires_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'error' => $run->error_message,
            'download_url' => $downloadUrl,
        ];
    }
}
