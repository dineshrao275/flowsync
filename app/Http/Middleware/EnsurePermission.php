<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'This action is unauthorized.');
        }

        if ($user->is_super_admin) {
            return $next($request);
        }

        if (! $user->hasPermission($permission)) {
            abort(403, 'This action is unauthorized.');
        }

        return $next($request);
    }
}
