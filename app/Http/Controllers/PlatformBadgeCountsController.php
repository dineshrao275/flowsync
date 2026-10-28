<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\PlatformBadgeCounts;
use Illuminate\Http\JsonResponse;

/**
 * Real-time badge counts for the Super Admin sidebar (leads, support, queue).
 */
class PlatformBadgeCountsController extends Controller
{
    public function __construct(private readonly PlatformBadgeCounts $badgeCounts) {}

    public function index(): JsonResponse
    {
        return response()->json($this->badgeCounts->counts());
    }
}
