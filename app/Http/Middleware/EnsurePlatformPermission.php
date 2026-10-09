<?php

namespace App\Http\Middleware;

use App\Services\Security\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit platform permission gate (P8.3): `platform:<slug>` for routes outside
 * the `super_admin` groups (or that need a stricter slug than the route map
 * gives). The caller must be a platform account; persona-narrowed accounts must
 * hold the slug, break-glass accounts pass.
 */
class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin) {
            abort(403, 'Super administrator access is required for this action.');
        }

        if (! app(PlatformAccess::class)->allows($user, $slug)) {
            return response()->json([
                'message' => 'Your platform role does not allow this action.',
                'code' => 'platform_permission_denied',
                'permission' => $slug,
            ], 403);
        }

        return $next($request);
    }
}
