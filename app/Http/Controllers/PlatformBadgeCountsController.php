<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketingLead;
use App\Models\SupportTicket;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Real-time badge counts for the Super Admin sidebar (leads, support, queue).
 */
class PlatformBadgeCountsController extends Controller
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    public function index(): JsonResponse
    {
        $db = DB::connection($this->dbm->centralConnectionName());

        $leadsCount = MarketingLead::where('status', MarketingLead::STATUS_NEW)->count();

        $supportCount = SupportTicket::whereNotIn('status', [
            SupportTicket::STATUS_RESOLVED,
            SupportTicket::STATUS_CLOSED,
        ])->count();

        $failedQueueCount = $db->table('failed_jobs')->count();

        return response()->json([
            'leads' => $leadsCount,
            'support' => $supportCount,
            'queue' => $failedQueueCount,
        ]);
    }
}
