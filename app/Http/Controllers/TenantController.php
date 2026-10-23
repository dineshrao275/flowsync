<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanInput;
use App\Http\Controllers\Concerns\RequiresTenantAdmin;
use App\Http\Controllers\Concerns\ValidatesResourceLimits;
use App\Http\Requests\TenantUpdateProfileRequest;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\TenantLifecycle;
use App\Support\Like;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use App\Support\Tms\TmsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    use NormalizesBooleanInput;
    use RequiresTenantAdmin;
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
                Tenant::STATUS_DRAFT,
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
            'subscription_status' => ['nullable', 'string', Rule::in(['trialing', 'active', 'past_due', 'canceled', 'expired', 'ended', 'none'])],
            'trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::query();

        if (! empty($data['q'])) {
            Like::any($query, ['name', 'slug', 'description'], $data['q']);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['plan_id'])) {
            $query->whereHas('subscription', fn ($sub) => $sub->where('plan_id', $data['plan_id']));
        }

        if (! empty($data['subscription_status'])) {
            $data['subscription_status'] === 'none'
                ? $query->whereDoesntHave('subscription')
                : $query->whereHas('subscription', fn ($sub) => $sub->where('status', $data['subscription_status']));
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
            $tenant->setAttribute('trial_ends_at', $subscription?->trial_ends_at ?? $tenant->trial_ends_at);
            $tenant->setAttribute('period_end', $subscription?->current_period_end);
            $tenant->setAttribute('billing_provider', $subscription?->billing_provider);
            $tenant->setAttribute('auto_renew', $subscription?->auto_renew);

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

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $this->counts($tenant),
            'default_user' => $this->defaultUser($tenant),
            'subscription' => $tenant->subscription?->load('plan'),
        ]);
    }

    /**
     * The tenant's default user, read from its own database. Null while the
     * tenant is a draft or not provisioned (there is no database to read).
     *
     * @return array{id: int, name: string, email: string}|null
     */
    private function defaultUser(Tenant $tenant): ?array
    {
        if (! $tenant->isProvisioned()) {
            return null;
        }

        try {
            $user = app(TenantDatabaseManager::class)->using($tenant, fn () => User::defaultUser());
        } catch (\Throwable $e) {
            Log::warning('Tenant default user lookup failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
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
            'message' => __('Tenant updated.'),
            'tenant' => $this->counts($tenant),
        ]);
    }

    public function getProfile(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $tenant,
        ]);
    }

    public function updateProfile(TenantUpdateProfileRequest $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validated();

        $tenant->update($data);

        return response()->json([
            'message' => __('Tenant profile updated.'),
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
        $this->requireTenantAdmin($request, 'Only tenant admins can edit the company profile.');

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
            'message' => __('Business profile saved.'),
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        app(PlatformAudit::class)->record(
            $request,
            'tenant.deleted',
            Tenant::class,
            $tenant->id,
            ['name' => $tenant->name, 'slug' => $tenant->slug],
        );

        return response()->json(['message' => __('Tenant deleted.')]);
    }

    public function restore(Request $request, Tenant $tenant): JsonResponse
    {
        if (($tenant->settings['purged_at'] ?? null) !== null) {
            throw ValidationException::withMessages(['form' => __('This tenant\'s data was permanently purged and cannot be restored.')]);
        }

        $tenant->restore();

        app(PlatformAudit::class)->record(
            $request,
            'tenant.restored',
            Tenant::class,
            $tenant->id,
            ['name' => $tenant->name, 'slug' => $tenant->slug],
        );

        return response()->json(['message' => __('Tenant restored.'), 'tenant' => $this->counts($tenant)]);
    }

    public function suspend(Tenant $tenant): JsonResponse
    {
        app(TenantLifecycle::class)->transition($tenant, Tenant::STATUS_SUSPENDED, auth()->user());

        return response()->json([
            'message' => __('Tenant suspended.'),
            'tenant' => $this->counts($tenant),
        ]);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        app(TenantLifecycle::class)->transition($tenant, Tenant::STATUS_ACTIVE, auth()->user());

        return response()->json([
            'message' => __('Tenant activated.'),
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
                    // Single-product tenants hold no TMS tables: zero, not a 500.
                    'workspaces' => TmsSchema::present() ? $db->table('workspaces')->count() : 0,
                    'projects' => TmsSchema::present() ? $db->table('projects')->count() : 0,
                    'tasks' => TmsSchema::present() ? $db->table('tasks')->whereNull('deleted_at')->count() : 0,
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
