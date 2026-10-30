<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\MarketingLead;
use App\Models\SupportTicket;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Computes platform-level unread/pending badge counts for the Super Admin shell.
 */
class PlatformBadgeCounts
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /**
     * @return array{leads: int, support: int, queue: int}
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

        return [
            'leads' => $leadsCount,
            'support' => $supportCount,
            'queue' => $failedQueueCount,
        ];
    }
}
