<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImpersonationStartRequest;
use App\Models\ImpersonationLog;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function start(ImpersonationStartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $superAdmin = $request->user();

        return $this->startIsolated($request, $data, $superAdmin);
    }

    public function stop(Request $request): JsonResponse
    {
        $impersonation = $request->session()->get('impersonate');

        if (! $impersonation) {
            throw ValidationException::withMessages([
                'impersonate' => 'You are not impersonating any user.',
            ]);
        }

        $dbm = app(TenantDatabaseManager::class);
        $dbm->connectSystem();

        $original = $impersonation['original_user_id']
            ? User::find($impersonation['original_user_id'])
            : null;

        if ($original) {
            Auth::login($original);
        } else {
            Auth::logout();
        }

        $log = ImpersonationLog::find($impersonation['log_id']);
        if ($log && ! $log->ended_at) {
            $log->update(['ended_at' => now()]);
        }

        $request->session()->forget('impersonate');
        $request->session()->forget('login.tenant_id');
        $request->session()->regenerate();

        return app(AuthController::class)->me($request);
    }

    /**
     * Isolated impersonation: `user_id` is the id of a user INSIDE a tenant DB,
     * so it is only unique per tenant — the same id is cloned across tenants.
     * Resolve the owning tenant via the central routing index, scoped to the
     * optional `tenant_id` disambiguator (the super admin UI always sends it),
     * connect, and log in.
     */
    private function startIsolated(Request $request, array $data, User $superAdmin): JsonResponse
    {
        $query = TenantUserRouting::with('tenant');

        if (! empty($data['tenant_id'])) {
            $query->where('tenant_id', $data['tenant_id']);
        }

        $route = $query->where('user_id', $data['user_id'])->first();

        if (! $route || ! $route->tenant || ! $route->tenant->isServiceable()) {
            throw ValidationException::withMessages([
                'user_id' => 'You cannot impersonate this user.',
            ]);
        }

        $tenant = $route->tenant;

        return app(TenantDatabaseManager::class)->using($tenant, function () use ($request, $data, $superAdmin, $tenant) {
            $target = User::find($data['user_id']);

            if (! $target) {
                throw ValidationException::withMessages([
                    'user_id' => 'You cannot impersonate this user.',
                ]);
            }

            $log = ImpersonationLog::create([
                'super_admin_id' => $superAdmin->id,
                'tenant_id' => $tenant->id,
                'impersonated_user_id' => $target->id,
                'ip_address' => $request->ip(),
                'started_at' => now(),
            ]);

            $request->session()->put('impersonate', [
                'log_id' => $log->id,
                'tenant_id' => $tenant->id,
                'original_user_id' => $superAdmin->id,
                'original_user_name' => $superAdmin->name,
            ]);

            Auth::login($target);
            $request->session()->regenerate();

            return app(AuthController::class)->me($request);
        });
    }
}
