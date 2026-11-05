<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `hrms_read_only` — HR data is view-only through API tokens (and so through the MCP). The `/api/v1` HRMS routes
 * are GET-only today; this guard makes that a rule instead of a coincidence, so a write route added to the group
 * later is refused rather than silently reachable by an agent.
 */
class HrmsReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return response()->json([
                'message' => 'HR data is read-only through API tokens. Make this change in the app.',
                'code' => 'hrms_read_only',
            ], 403);
        }

        return $next($request);
    }
}
