<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Services\UsageMeter;
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

        $limits = app(TenantLimits::class);
        $meter = app(UsageMeter::class);

        $modules = $tenant ? $limits->visibleModules($tenant) : [];
        $products = [
            'tms' => $tenant ? $limits->productEnabled($tenant, 'tms') : true,
            'hrms' => $tenant ? $limits->productEnabled($tenant, 'hrms') : true,
        ];

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'tenant' => ['id' => $tenant?->id, 'name' => $tenant?->name, 'slug' => $tenant?->slug],
            'token' => [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'can_write' => $token->can_write,
                'rate_limit' => $token->rate_limit ?? (int) config('api.default_rate_limit'),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            'modules' => $modules,
            'products' => $products,
            'usage' => [
                'api_calls' => [
                    'used' => $meter->used(UsageMeter::API_CALLS),
                    'limit' => $meter->limit(UsageMeter::API_CALLS),
                ],
            ],
        ]);
    }
}
