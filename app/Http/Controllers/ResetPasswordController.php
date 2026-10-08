<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetPasswordRequest;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function update(ResetPasswordRequest $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $routes = TenantUserRouting::where('email', $email)->with('tenant')->get();

        if ($tenantSlug = $request->input('tenant')) {
            $routes = $routes->filter(fn ($route) => $route->tenant?->slug === $tenantSlug)->values();
        }

        $tenant = $routes->count() === 1 ? $routes->first()->tenant : null;

        $resetAction = function () use ($request) {
            return Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();
                }
            );
        };

        $status = ($tenant && $tenant->isServiceable())
            ? app(TenantDatabaseManager::class)->using($tenant, $resetAction)
            : $resetAction();

        return response()->json([
            'message' => __($status),
            'status' => $status,
        ], $status === Password::PASSWORD_RESET ? 200 : 422);
    }
}
