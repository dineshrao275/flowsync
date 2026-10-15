<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The /api/v1 endpoints that exist only for tokens; the rest reuse the session controllers. */
class ApiV1Controller extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        $user = $request->user();
        $tenant = Tenant::find(app(TenantContext::class)->currentId());

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'tenant' => ['id' => $tenant?->id, 'name' => $tenant?->name, 'slug' => $tenant?->slug],
            'token' => ['name' => $token->name, 'abilities' => $token->abilities, 'can_write' => $token->can_write, 'expires_at' => $token->expires_at?->toIso8601String()],
        ]);
    }
}
