<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Http\Requests\ApiTokenStoreRequest;
use App\Models\ApiToken;
use App\Services\Api\ApiTokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A person manages their OWN tokens (gated by `api.manage` + the `api` module). Another
 * person's token answers 404, never 403 — its existence is not the caller's business.
 */
class ApiTokenController extends Controller
{
    use AuditsTenantAdminActions;

    public function __construct(private readonly ApiTokenService $tokens) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'tokens' => ApiToken::where('user_id', $request->user()->id)->orderByDesc('id')->get()->map(fn (ApiToken $t) => $this->present($t)),
            // Only what the caller actually holds can be granted.
            'abilities' => collect((array) config('api.abilities'))
                ->filter(fn ($label, $slug) => $request->user()->hasPermission($slug))
                ->map(fn ($label, $slug) => ['slug' => $slug, 'label' => $label])->values(),
            'max_rate_limit' => (int) config('api.max_rate_limit'),
            'default_rate_limit' => (int) config('api.default_rate_limit'),
        ]);
    }

    public function store(ApiTokenStoreRequest $request): JsonResponse
    {
        $issued = $this->tokens->issue($request->user(), (int) app(TenantContext::class)->currentId(), $request->validated());
        $token = $issued['token'];

        $this->auditTenantAdmin($request, 'api_token.created', 'api_tokens', $token->id, null, [
            'name' => $token->name, 'abilities' => $token->abilities, 'can_write' => $token->can_write, 'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        // The one and only time the plaintext token is shown.
        return response()->json(['message' => 'Token created.', 'token' => $this->present($token), 'plaintext' => $issued['plaintext']], 201);
    }

    public function destroy(Request $request, ApiToken $apiToken): JsonResponse
    {
        $this->ownOr404($request, $apiToken);
        $this->tokens->revoke($apiToken);
        $this->auditTenantAdmin($request, 'api_token.revoked', 'api_tokens', $apiToken->id, ['name' => $apiToken->name], null);

        return response()->json(['message' => 'Token revoked.']);
    }

    public function logs(Request $request, ApiToken $apiToken): JsonResponse
    {
        $this->ownOr404($request, $apiToken);

        return response()->json(['logs' => $apiToken->logs()->orderByDesc('id')->limit(100)->get()]);
    }

    private function ownOr404(Request $request, ApiToken $token): void
    {
        abort_unless($token->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(ApiToken $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'hint' => '…'.$t->token_hint, 'abilities' => $t->abilities,
            'can_write' => $t->can_write, 'rate_limit' => $t->rate_limit,
            'expires_at' => $t->expires_at?->toIso8601String(), 'last_used_at' => $t->last_used_at?->toIso8601String(),
            'last_used_ip' => $t->last_used_ip, 'revoked_at' => $t->revoked_at?->toIso8601String(),
            'active' => $t->isUsable(), 'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
