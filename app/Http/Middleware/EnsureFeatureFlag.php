<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate on a runtime feature flag (P8.7): `ensure_flag:<key>`. Resolves for
 * the current tenant context (override -> global -> rollout); a request with no
 * tenant (platform) is judged on the global switch alone. Unknown flag = 403.
 */
class EnsureFeatureFlag
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! app(FeatureFlags::class)->enabled($flag)) {
            return response()->json(['message' => 'This feature is not enabled.', 'flag' => $flag], 403, ['X-Feature-Flag' => $flag]);
        }

        return $next($request);
    }
}
