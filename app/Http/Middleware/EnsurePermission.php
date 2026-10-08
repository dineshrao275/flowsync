<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * The one sentence a route-gate denial answers with. Named so a caller
     * who hits a management surface they lack reads WHICH grant is missing
     * instead of a bare "unauthorized" — `fieldErrors()` passes it straight
     * into the page's alert. The Gate definition reuses it so a policy-side
     * `Gate::authorize('permission', …)` can never word it differently.
     */
    public static function denial(string $permission): string
    {
        return "The \"{$permission}\" permission is required for this action.";
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Authentication is required for this action.');
        }

        if ($user->is_super_admin && ! app(TenantContext::class)->impersonating()) {
            return $next($request);
        }

        if (! $user->hasPermission($permission)) {
            abort(403, self::denial($permission));
        }

        return $next($request);
    }
}
