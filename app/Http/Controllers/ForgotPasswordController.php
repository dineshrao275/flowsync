<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Models\TenantUserRouting;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    public function create(ForgotPasswordRequest $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $routes = TenantUserRouting::where('email', $email)->with('tenant')->get();

        if ($tenantSlug = $request->input('tenant')) {
            $routes = $routes->filter(fn ($route) => $route->tenant?->slug === $tenantSlug)->values();
        }

        $tenant = $routes->count() === 1 ? $routes->first()->tenant : null;

        if ($tenant && $tenant->isServiceable()) {
            app(TenantDatabaseManager::class)->using($tenant, function () use ($request) {
                Password::sendResetLink($request->only('email'));
            });
        } else {
            Password::sendResetLink($request->only('email'));
        }

        // Uniform response either way: echoing RESET_LINK_SENT vs INVALID_USER
        // (plus the status field) tells an attacker which store holds the
        // account. Whether the mail actually sends is a delivery concern, not
        // an API answer.
        return response()->json([
            'message' => 'If that address belongs to an account, a reset link is on its way.',
        ]);
    }
}
