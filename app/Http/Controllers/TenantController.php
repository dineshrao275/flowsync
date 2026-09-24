<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionTenantJob;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function index(): JsonResponse
    {
        // users/roles live in per-tenant DBs; surface the routing-index counts.
        $counts = TenantUserRouting::query()
            ->select('tenant_id', DB::raw('COUNT(*) as user_count'))
            ->groupBy('tenant_id')
            ->pluck('user_count', 'tenant_id');

        $tenants = Tenant::orderBy('name')->get()->each(function (Tenant $tenant) use ($counts): void {
            $tenant->setAttribute('users_count', (int) ($counts[$tenant->id] ?? 0));
            $tenant->setAttribute('roles_count', 0);
        });

        return response()->json(['tenants' => $tenants]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = Tenant::create([
            ...$data,
            'slug' => Str::slug($data['slug']),
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);

        // Async provisioning (QUEUE sync in tests runs it inline).
        ProvisionTenantJob::dispatch($tenant);

        return response()->json([
            'message' => 'Tenant creation queued for provisioning.',
            'tenant' => $this->counts($tenant),
        ], 202);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $this->counts($tenant),
        ]);
    }

    public function users(Tenant $tenant): JsonResponse
    {
        $users = TenantUserRouting::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($route) => [
                'id' => $route->user_id,
                'name' => $route->name,
                'email' => $route->email,
                'roles' => [],
            ]);

        return response()->json(['users' => $users]);
    }

    private function counts(Tenant $tenant): Tenant
    {
        $tenant->setAttribute('users_count', TenantUserRouting::where('tenant_id', $tenant->id)->count());
        $tenant->setAttribute('roles_count', 0);

        return $tenant;
    }
}