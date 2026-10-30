<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Resolves user directory listings and default owner accounts for tenant administration.
 */
class TenantUserDirectory
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /**
     * Sorted user list for tenant administration and impersonation.
     *
     * @return list<array{id: int, name: string, email: string, roles: list<string>, role_names: list<string>, is_default: bool}>
     */
    public function users(Tenant $tenant): array
    {
        $roleWeight = function (array $roles, bool $isDefault): int {
            if ($isDefault) {
                return 0;
            }
            if (in_array('admin', $roles, true)) {
                return 1;
            }
            if (in_array('manager', $roles, true)) {
                return 2;
            }
            if (in_array('editor', $roles, true)) {
                return 3;
            }
            if (in_array('member', $roles, true)) {
                return 4;
            }
            if (in_array('viewer', $roles, true)) {
                return 5;
            }

            return 10;
        };

        try {
            $users = $this->dbm->using($tenant, function () use ($roleWeight) {
                return User::with('roles')
                    ->get()
                    ->sort(function (User $a, User $b) use ($roleWeight) {
                        $wA = $roleWeight($a->roles->pluck('slug')->all(), (bool) $a->is_default);
                        $wB = $roleWeight($b->roles->pluck('slug')->all(), (bool) $b->is_default);
                        if ($wA !== $wB) {
                            return $wA <=> $wB;
                        }

                        return strcasecmp($a->name, $b->name);
                    })
                    ->values()
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('slug')->values()->all(),
                        'role_names' => $user->roles->pluck('name')->values()->all(),
                        'is_default' => (bool) $user->is_default,
                    ])
                    ->all();
            });

            if (! empty($users)) {
                return $users;
            }
        } catch (\Throwable) {
            // fall through to central routing table
        }

        return TenantUserRouting::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($route) => [
                'id' => $route->user_id,
                'name' => $route->name,
                'email' => $route->email,
                'roles' => [],
                'role_names' => [],
                'is_default' => false,
            ])
            ->all();
    }

    /**
     * The tenant's default user, read from its own database. Null while the
     * tenant is a draft or not provisioned (there is no database to read).
     *
     * @return array{id: int, name: string, email: string}|null
     */
    public function defaultUser(Tenant $tenant): ?array
    {
        if (! $tenant->isProvisioned()) {
            return null;
        }

        try {
            $user = $this->dbm->using($tenant, fn () => User::defaultUser());
        } catch (\Throwable $e) {
            Log::warning('Tenant default user lookup failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
    }
}
