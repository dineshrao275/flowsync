<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `token_can:<slug>` — the route-level half of a token's authorization: the token
 * itself must carry the ability. The owner's real rights are still judged by the
 * controller's policy, so a token can narrow its owner but never widen them.
 */
class TokenCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken || ! $token->allows($ability)) {
            abort(403, "This API token lacks the \"{$ability}\" ability.");
        }

        return $next($request);
    }
}
