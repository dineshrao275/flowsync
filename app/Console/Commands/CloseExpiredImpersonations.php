<?php

namespace App\Console\Commands;

use App\Models\ImpersonationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stamp `ended_at` on impersonation sessions that lapsed without anyone making
 * another request (a closed laptop never calls "stop"). The request-time check
 * in ImpersonationGuard ends a session the moment it is touched after expiry;
 * this closes the ones nobody touches, so the audit feed never shows a session
 * as still open hours after the server would have refused it.
 */
class CloseExpiredImpersonations extends Command
{
    protected $signature = 'tenants:close-impersonations';

    protected $description = 'Close impersonation sessions whose time box has expired';

    public function handle(): int
    {
        $closed = ImpersonationLog::query()
            ->whereNull('ended_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['ended_at' => DB::raw('expires_at')]);

        $this->info("Closed {$closed} expired impersonation session(s).");

        return self::SUCCESS;
    }
}
