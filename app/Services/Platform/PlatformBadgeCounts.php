<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\ErrorLog;
use App\Models\MarketingLead;
use App\Models\SupportTicket;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computes platform-level unread/pending badge counts for the Super Admin shell.
 */
class PlatformBadgeCounts
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /**
     * @return array{leads: int, support: int, queue: int, errors: int}
     */
    public function counts(): array
    {
        $db = DB::connection($this->dbm->centralConnectionName());

        $leadsCount = MarketingLead::where('status', MarketingLead::STATUS_NEW)->count();

        $supportCount = SupportTicket::whereNotIn('status', [
            SupportTicket::STATUS_RESOLVED,
            SupportTicket::STATUS_CLOSED,
        ])->count();

        $failedQueueCount = $db->table('failed_jobs')->count();

        $errorsCount = Schema::connection($this->dbm->centralConnectionName())->hasTable('error_logs')
            ? ErrorLog::whereIn('status', [ErrorLog::STATUS_OPEN, ErrorLog::STATUS_INVESTIGATING])->count()
            : 0;

        return [
            'leads' => $leadsCount,
            'support' => $supportCount,
            'queue' => $failedQueueCount,
            'errors' => $errorsCount,
        ];
    }
}
