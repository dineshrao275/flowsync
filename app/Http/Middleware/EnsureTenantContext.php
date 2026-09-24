<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TenantContext::class)->hasTenant()) {
            abort(403, 'This action is only available within a tenant.');
        }

        return $next($request);
    }
}
