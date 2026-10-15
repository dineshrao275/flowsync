<?php

namespace App\Http\Middleware;

use App\Services\Security\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform gate. First: the caller must be a platform account (`is_super_admin`).
 * Then (P8.3): an account narrowed by persona roles must hold the permission the
 * route maps to in config/platform_access.php - an unmapped route is denied to
 * it. An account with no persona is the unrestricted break-glass admin.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin) {
            abort(403, 'Super administrator access is required for this action.');
        }

        $access = app(PlatformAccess::class);

        if (! $access->isUnrestricted($user)) {
            $slug = $access->slugFor($request);

            if ($slug === null || ! $access->allows($user, $slug)) {
                return response()->json([
                    'message' => 'Your platform role does not allow this action.',
                    'code' => 'platform_permission_denied',
                    'permission' => $slug,
                ], 403);
            }
        }

        return $next($request);
    }
}
