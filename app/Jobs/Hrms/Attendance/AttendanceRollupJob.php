<?php

namespace App\Jobs\Hrms\Attendance;

use App\Models\Tenant;
use App\Services\Hrms\AttendanceService;
use App\Support\TenantDatabaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attendance/HRMS — roll one tenant's day rows up in the background.
 *
 * The UI backfill button's worker (wired in P5.6): the web request answers
 * fast and this does the row loop. The tenant travels as a central id, not
 * a model and not ambient state — a queued job unserializes on whatever
 * connection the worker happens to hold, so resolving the tenant inside
 * `using()` is what makes this correct from any queue, and `tries = 1`
 * with no retry: a re-run is the repair path, and a blind retry would
 * double-log a partial pass as two.
 */
class AttendanceRollupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $tenantId,
        public string $workDate,
    ) {}

    public function handle(AttendanceService $attendance, TenantDatabaseManager $dbm): void
    {
        $started = microtime(true);

        Log::channel('hrms')->info('attendance.rollup.job_started', [
            'tenant_id' => $this->tenantId,
            'work_date' => $this->workDate,
        ]);

        try {
            // CentralConnection pins Tenant to the system DB, so this lookup
            // is correct no matter which connection the worker woke up on.
            $tenant = Tenant::findOrFail($this->tenantId);

            if (! $tenant->isServiceable()) {
                Log::channel('hrms')->warning('attendance.rollup.job_skipped', [
                    'tenant_id' => $this->tenantId,
                    'work_date' => $this->workDate,
                    'reason' => 'tenant is not serviceable',
                ]);

                return;
            }

            $result = $dbm->using($tenant, fn (): array => $attendance->rollup($this->workDate));

            Log::channel('hrms')->info('attendance.rollup.job_succeeded', [
                'tenant_id' => $this->tenantId,
                'work_date' => $this->workDate,
                'employees' => $result['employees'],
                'ensured' => $result['ensured'],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $exception) {
            Log::channel('hrms')->error('attendance.rollup.job_failed', [
                'tenant_id' => $this->tenantId,
                'work_date' => $this->workDate,
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
