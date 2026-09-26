<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanInput;
use App\Http\Controllers\Concerns\ValidatesResourceLimits;
use App\Jobs\ProvisionTenantJob;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Services\TenantLifecycle;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    use NormalizesBooleanInput;
    use ValidatesResourceLimits;

    protected const SORTABLE = ['name', 'slug', 'status', 'created_at', 'updated_at', 'users_count'];

    public function index(Request $request): JsonResponse
    {
        // users/roles live in per-tenant DBs; surface the routing-index counts.
        // The UI sends `trashed=false` in the query string, so normalize the
        // boolean-ish query values before the strict `boolean` rule runs.
        $this->normalizeRequestBooleans($request, ['trashed']);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([
                Tenant::STATUS_PENDING,
                Tenant::STATUS_PROVISIONING,
                Tenant::STATUS_TRIAL,
                Tenant::STATUS_ACTIVE,
                Tenant::STATUS_SUSPENDED,
                Tenant::STATUS_EXPIRED,
                Tenant::STATUS_DEACTIVATED,
                Tenant::STATUS_PROVISIONING_FAILED,
            ])],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::query();

        if (! empty($data['q'])) {
            $q = $data['q'];
            $query->where(function ($sub) use ($q): void {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['plan_id'])) {
            $query->whereHas('subscription', fn ($sub) => $sub->where('plan_id', $data['plan_id']));
        }

        if (filter_var($data['trashed'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->onlyTrashed();
        }

        $dir = $data['dir'] ?? 'asc';
        $sort = $data['sort'] ?? 'name';
        if ($sort === 'users_count') {
            $query->withCount('routingUsers as users_count')->orderBy('users_count', $dir);
        } else {
            $query->orderBy($sort, $dir);
        }

        $perPage = (int) ($data['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);
        $paginator->load(['subscription.plan']);

        $counts = TenantUserRouting::query()
            ->whereIn('tenant_id', $paginator->pluck('id'))
            ->select('tenant_id', DB::raw('COUNT(*) as user_count'))
            ->groupBy('tenant_id')
            ->pluck('user_count', 'tenant_id');

        $tenants = $paginator->getCollection()->map(function (Tenant $tenant) use ($counts) {
            $tenant->setAttribute('users_count', (int) ($counts[$tenant->id] ?? 0));

            $subscription = $tenant->subscription;
            $tenant->setAttribute('plan_slug', $subscription?->plan?->slug);
            $tenant->setAttribute('plan_name', $subscription?->plan?->name);
            $tenant->setAttribute('subscription_status', $subscription?->status);
            $tenant->setAttribute('subscription_ends_at', $subscription?->ends_at);

            return $tenant;
        });

        return response()->json([
            'tenants' => $tenants->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug'],
            'description' => ['nullable', 'string', 'max:255'],
            // Phase 14: onboarding plan + trial (provisioned after the DB exists).
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        // The plan catalog must exist before the provisioning job plans anything.
        (new SubscriptionPlanSeeder)->run();

        $trialDays = $data['trial_days'] ?? null;

        $tenant = Tenant::create([
            ...Arr::except($data, ['plan_id', 'trial_days']),
            'slug' => Str::slug($data['slug']),
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
            'trial_ends_at' => $trialDays ? now()->addDays((int) $trialDays) : null,
        ]);

        // Async provisioning (QUEUE sync in tests runs it inline).
        ProvisionTenantJob::dispatch($tenant, $data['plan_id'] ?? null, $trialDays);

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

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug,'.$tenant->id],
            'description' => ['nullable', 'string', 'max:255'],
            // Per-tenant caps that win over the plan's (null key = unlimited).
            'limits_override' => ['nullable', 'array'],
            ...$this->resourceLimitRules('limits_override'),
        ]);

        $tenant->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug']),
            'description' => $data['description'] ?? null,
            'limits_override' => $this->cleanResourceLimits($data['limits_override'] ?? null, 'limits_override'),
        ]);

        return response()->json([
            'message' => 'Tenant updated.',
            'tenant' => $this->counts($tenant),
        ]);
    }

    public function getProfile(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $tenant,
        ]);
    }

    public function updateProfile(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'company_size' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'billing_currency' => ['nullable', 'string', 'max:3'],
            'timezone' => ['nullable', 'string', 'max:128'],
            'locale' => ['nullable', 'string', 'max:16'],
            'brand_domain' => ['nullable', 'string', 'max:255'],
            'brand_logo_url' => ['nullable', 'url', 'max:255'],
            'brand_primary_color' => ['nullable', 'string', 'max:9'],
        ]);

        $tenant->update($data);

        return response()->json([
            'message' => 'Tenant profile updated.',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Tenant-facing profile read: the current tenant user resolves their own
     * (central) tenant via TenantContext and reads its profile. Requires a
     * tenant context (a non-impersonating super admin has none).
     */
    public function selfProfile(Request $request): JsonResponse
    {
        abort_unless(app(TenantContext::class)->currentId(), 404);

        $tenant = Tenant::findOrFail(app(TenantContext::class)->currentId());

        return response()->json(['tenant' => $tenant]);
    }

    /**
     * Tenant-facing profile write: the onboarding wizard's business step calls
     * this. Deliberately a narrow subset of `updateProfile` (which is
     * `super_admin`-gated and takes a route-bound Tenant) — a tenant user may
     * only fill in the fields the wizard owns, never billing, plan, or
     * entitlement fields. `nullable` throughout so a partial save cannot blank
     * a field the wizard did not send.
     */
    public function updateSelfProfile(Request $request): JsonResponse
    {
        abort_unless(app(TenantContext::class)->currentId(), 404);

        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'company_size' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'website' => ['nullable', 'url', 'max:255'],
        ]);

        $tenant = Tenant::findOrFail(app(TenantContext::class)->currentId());

        // Only write keys the request actually sent, so `null` (absent) does not
        // overwrite an existing value with null.
        $tenant->update(array_filter($data, fn ($value) => $value !== null));

        return response()->json([
            'message' => 'Business profile saved.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        AuditLog::create([
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'action' => 'tenant.deleted',
            'data' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'actor_id' => auth()->id(),
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Tenant deleted.']);
    }

    public function restore(Tenant $tenant): JsonResponse
    {
        $tenant->restore();

        AuditLog::create([
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'action' => 'tenant.restored',
            'data' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'actor_id' => auth()->id(),
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['message' => 'Tenant restored.', 'tenant' => $this->counts($tenant)]);
    }

    public function suspend(Tenant $tenant): JsonResponse
    {
        app(TenantLifecycle::class)->transition($tenant, Tenant::STATUS_SUSPENDED, auth()->user());

        return response()->json([
            'message' => 'Tenant suspended.',
            'tenant' => $this->counts($tenant),
        ]);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        app(TenantLifecycle::class)->transition($tenant, Tenant::STATUS_ACTIVE, auth()->user());

        return response()->json([
            'message' => 'Tenant activated.',
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

    /**
     * Live domain counts read from the tenant's own database. Cheap enough to
     * recompute, so results are cached for 60 seconds per tenant.
     */
    public function stats(Tenant $tenant): JsonResponse
    {
        $stats = Cache::remember("tenants.stats.{$tenant->id}", 60, function () use ($tenant) {
            return app(TenantDatabaseManager::class)->using($tenant, function () {
                $db = DB::connection('tenant');

                return [
                    'users' => $db->table('users')->count(),
                    'workspaces' => $db->table('workspaces')->count(),
                    'projects' => $db->table('projects')->count(),
                    'tasks' => $db->table('tasks')->whereNull('deleted_at')->count(),
                ];
            });
        });

        return response()->json(['stats' => $stats]);
    }

    private function counts(Tenant $tenant): Tenant
    {
        $tenant->setAttribute('users_count', TenantUserRouting::where('tenant_id', $tenant->id)->count());

        return $tenant;
    }
}
