<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AppName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Points `config('app.name')` at the Super-Admin-edited product name for the whole request (see AppName). */
class ApplyAppName
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            AppName::apply();
        } catch (\Throwable) {
            // The name is cosmetic: never fail a request because the settings store is unreachable.
        }

        return $next($request);
    }
}
