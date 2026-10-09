<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Services\Api\ApiTokenService;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token door for the `/api/v1` routes (P2.7). It replaces the session trio
 * (`switch_tenant` + `auth` + `tenant`) for those routes only:
 *
 *  - the token names its tenant (`fst_<tenant>_<random>`), so the tenant database is
 *    connected BEFORE the lookup — the hash is then matched inside that tenant only;
 *  - the owner is loaded from the tenant database and set as the request user, so
 *    policies and row scopes judge the real person, never the token alone;
 *  - a token without write access is refused on any non-safe verb;
 *  - every call (including refusals) lands in `integration_logs`.
 *
 * Every refusal for a bad credential answers the same 401, so a probe cannot tell an
 * unknown token from a revoked one or from a suspended tenant.
 */
class AuthenticateApiToken
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly TenantDatabaseManager $dbm,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $plaintext = (string) $request->bearerToken();
        $tenant = $plaintext !== '' ? $this->tokens->tenantFor($plaintext) : null;

        if (! $tenant) {
            return $this->unauthenticated();
        }

        $this->dbm->connect($tenant);
        $this->context->setImpersonating(false);

        $token = $this->tokens->resolve($plaintext);
        $user = $token ? User::with('roles.permissions')->find($token->user_id) : null;

        if (! $token || ! $user) {
            $this->dbm->connectSystem();

            return $this->unauthenticated();
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        $response = $request->isMethodSafe() || $token->can_write
            ? $next($request)
            : response()->json(['message' => 'This API token is read-only.', 'code' => 'token_read_only'], 403);

        $this->record($request, $token, $response, $started);

        return $response;
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Invalid or expired API token.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }

    private function record(Request $request, ApiToken $token, Response $response, float $started): void
    {
        IntegrationLog::create([
            'token_id' => $token->id,
            'user_id' => $token->user_id,
            'method' => $request->method(),
            'path' => substr($request->path(), 0, 255),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ip' => $request->ip(),
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);

        // One write per minute at most; "last used" is a hint, not a ledger.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        }
    }
}
