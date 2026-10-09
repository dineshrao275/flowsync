<?php

namespace App\Services\Security;

use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Support\TenantDatabaseManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform personas on top of `is_super_admin` (P8.3).
 *
 * A platform account with no persona role is unrestricted (break-glass, the
 * pre-persona behaviour). Persona roles narrow it to the union of their
 * permissions. `slugFor()` maps a platform route to the permission it needs
 * from config/platform_access.php, so the existing `super_admin` route groups
 * enforce personas without per-route edits. Bound `scoped`: one request/job.
 */
class PlatformAccess
{
    /** @var array<int, list<string>|null> user id => slugs, null = unrestricted */
    private array $memo = [];

    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /** @return list<string>|null null = unrestricted break-glass */
    public function permissionsOf(Authenticatable $user): ?array
    {
        $id = (int) $user->getAuthIdentifier();

        if (! array_key_exists($id, $this->memo)) {
            $this->memo[$id] = $this->load($id);
        }

        return $this->memo[$id];
    }

    public function isUnrestricted(Authenticatable $user): bool
    {
        return $this->permissionsOf($user) === null;
    }

    public function allows(Authenticatable $user, string $slug): bool
    {
        $held = $this->permissionsOf($user);

        return $held === null || in_array($slug, $held, true);
    }

    /** Permission a platform route needs, null when the map has no entry (fail-closed for restricted accounts). */
    public function slugFor(Request $request): ?string
    {
        $uri = $request->route()?->uri() ?? '';
        $read = in_array($request->method(), ['GET', 'HEAD'], true);

        foreach ((array) config('platform_access.routes', []) as [$pattern, $readSlug, $writeSlug]) {
            if (Str::is($pattern, $uri)) {
                return $read ? $readSlug : $writeSlug;
            }
        }

        return null;
    }

    /** @return list<string> slugs of the persona roles the account holds */
    public function roleSlugsOf(Authenticatable $user): array
    {
        return PlatformRole::query()
            ->whereIn('id', $this->roleIds((int) $user->getAuthIdentifier()))
            ->orderBy('slug')->pluck('slug')->all();
    }

    /** Idempotently create/refresh the catalog and persona roles from config. */
    public function syncCatalog(): void
    {
        $permissionIds = [];
        foreach (config('platform_access.permissions', []) as $slug => $description) {
            $permissionIds[$slug] = PlatformPermission::updateOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline(str_replace('.', ' ', $slug)), 'description' => $description],
            )->id;
        }

        foreach (config('platform_access.personas', []) as $slug => $persona) {
            $role = PlatformRole::updateOrCreate(
                ['slug' => $slug],
                ['name' => $persona['name'], 'description' => $persona['description']],
            );
            // Additive: a persona picks up new catalog grants but a repair never revokes one.
            $role->permissions()->syncWithoutDetaching(array_values(array_intersect_key($permissionIds, array_flip($persona['permissions']))));
        }
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<string>|null */
    private function load(int $userId): ?array
    {
        $roleIds = $this->roleIds($userId);
        if ($roleIds === []) {
            return null;
        }

        return PlatformPermission::query()
            ->whereIn('id', DB::connection($this->dbm->centralConnectionName())->table('platform_role_permission')
                ->whereIn('platform_role_id', $roleIds)->pluck('platform_permission_id'))
            ->pluck('slug')->unique()->values()->all();
    }

    /** @return list<int> */
    private function roleIds(int $userId): array
    {
        return DB::connection($this->dbm->centralConnectionName())->table('platform_user_role')
            ->where('user_id', $userId)->pluck('platform_role_id')->map(fn ($id) => (int) $id)->all();
    }
}
