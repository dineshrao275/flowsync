<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantLimits;
use App\Support\PermissionScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Why can't they?" (R14): explains one permission for one user.
 *
 * An admin staring at a 403 otherwise has to cross-read the user's roles, each
 * role's grants, the scope lattice and the plan's modules by hand. This walks
 * the same chain the server does — role grant (exact slug or a wider scope /
 * the legacy unsuffixed slug that satisfies it), then the plan module the
 * permission lives under — and says which link is missing.
 *
 * It reports; it never changes anything. Reading it needs `roles.view` because
 * it exposes how roles are composed.
 */
class UserAccessController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantLimits $limits,
    ) {}

    public function show(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'check' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9._]+$/'],
        ]);
        $slug = $data['check'];

        $known = Permission::where('slug', $slug)->exists();
        $accepted = PermissionScope::satisfying($slug);

        $grants = [];
        foreach ($user->load('roles.permissions')->roles as $role) {
            foreach ($role->permissions->pluck('slug')->intersect($accepted) as $via) {
                $grants[] = [
                    'role' => ['slug' => $role->slug, 'name' => $role->name],
                    'via' => $via,
                    'exact' => $via === $slug,
                ];
            }
        }

        $module = $this->moduleFor($slug);
        $moduleAvailable = $module === null ? null : $this->moduleAvailable($module);

        $granted = $grants !== [];
        $allowed = $granted && $moduleAvailable !== false;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'check' => $slug,
            'known_permission' => $known,
            'allowed' => $allowed,
            'granted_by' => $grants,
            'accepted_slugs' => $accepted,
            'module' => $module === null ? null : ['key' => $module, 'available' => $moduleAvailable],
            'reason' => $this->reason($known, $granted, $module, $moduleAvailable, $grants, $accepted),
        ]);
    }

    /** The plan module a permission lives under: the longest module key that prefixes it. */
    private function moduleFor(string $slug): ?string
    {
        $best = null;

        foreach (config('subscriptions.modules', []) as $module) {
            if (str_starts_with($slug, $module.'.') && ($best === null || strlen($module) > strlen($best))) {
                $best = $module;
            }
        }

        // `hrms.view` and friends sit under the HRMS umbrella module.
        return $best ?? (str_starts_with($slug, 'hrms.') ? 'hrms.core' : null);
    }

    private function moduleAvailable(string $module): bool
    {
        $tenant = Tenant::find($this->tenantContext->currentId());

        return $tenant === null || $this->limits->hasModule($tenant, $module);
    }

    /**
     * @param  list<array{role: array{slug: string, name: string}, via: string, exact: bool}>  $grants
     * @param  list<string>  $accepted
     */
    private function reason(bool $known, bool $granted, ?string $module, ?bool $moduleAvailable, array $grants, array $accepted): string
    {
        if (! $known) {
            return 'No permission with this slug exists in the catalog.';
        }

        if (! $granted) {
            return 'None of this user\'s roles grants it. A role would need one of: '.implode(', ', $accepted).'.';
        }

        if ($moduleAvailable === false) {
            return "A role grants it, but the plan does not include the {$module} module, so the server refuses it.";
        }

        $first = $grants[0];

        return $first['exact']
            ? "Granted by the {$first['role']['name']} role."
            : "Granted by the {$first['role']['name']} role through {$first['via']}, which covers this scope.";
    }
}
