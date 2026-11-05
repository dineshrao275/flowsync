<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `token_can:<slug>[,<slug>…]` — the route-level half of a token's authorization: the token
 * itself must carry the ability (any one of the listed slugs). The owner's real rights are still judged by the
 * controller's policy, so a token can narrow its owner but never widen them.
 */
class TokenCan
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->attributes->get('api_token');
        $ability = $abilities[0] ?? '';

        if (! $token instanceof ApiToken || ! collect($abilities)->contains(fn (string $slug): bool => $token->allows($slug))) {
            return response()->json([
                'message' => "This API token lacks the \"{$ability}\" ability.",
                'code' => 'token_ability_missing',
                'ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
