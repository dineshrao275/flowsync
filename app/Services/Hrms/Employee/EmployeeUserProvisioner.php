<?php

namespace App\Services\Hrms\Employee;

use App\Models\Role;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — creates the login account for a new hire who is being added
 * inline, rather than linked to an existing user.
 *
 * The plan calls this "the single place HRMS user provisioning lives": the
 * employee form is the one screen that creates a person, a login and a routing
 * row at once, and doing it here keeps the three in step.
 *
 * **The cross-connection caveat, stated plainly.** The `users` row lives in the
 * tenant database and the `tenant_users` routing row lives in the *central*
 * system database, so the two writes are on two different PostgreSQL servers
 * and **cannot** be made atomic without two-phase commit. What this class
 * guarantees is the direction that matters:
 *
 *   - the routing row is written **before** the tenant transaction commits, so
 *     the common failure (bad email, role slug that does not exist) throws
 *     inside the transaction and the whole create rolls back cleanly with no
 *     half-created account;
 *   - a failure to *commit* is not covered. If the central write succeeds and
 *     the tenant commit then fails, the residual is a routing row pointing at a
 *     user id that does not exist — which fails closed at login, and is
 *     repaired by `TenantProvisioner::syncRouting()`.
 *
 * The opposite ordering is deliberately not used: a committed `users` row with
 * no routing row is the P0 bug-1 case, an account that exists in the UI and can
 * never sign in, and it is invisible until the new hire reports it.
 */
class EmployeeUserProvisioner
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Create the login for a new hire.
     *
     * @param  array{name: string, email: string, password: string, roles?: list<string>}  $attributes
     *
     * @throws ValidationException when the email is already taken
     */
    public function create(array $attributes): User
    {
        // Normalised before the uniqueness check, and for the same reason as
        // `UserController::store`: login lower-cases the submitted address to
        // resolve the tenant, then matches `users.email` case-sensitively, so a
        // stored `New.Hire@Acme.Test` is only reachable by typing that casing.
        $email = Str::lower(trim($attributes['email']));

        $this->assertEmailAvailable($email);

        // Both checks resolve before the insert, deliberately. Validating after
        // it means a bad role slug leaves a real user behind — an account with
        // no roles, a routing row, and no employee record to explain it, since
        // the caller rolls its own half of the create back.
        $roleIds = $this->roleIds($attributes['roles'] ?? []);

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $email,
            // Hashed by the model's `password` cast.
            'password' => $attributes['password'],
        ]);

        $user->roles()->sync($roleIds);

        $this->writeRoutingRow($user, $email);

        return $user->load('roles');
    }

    /**
     * Whether this address is already in use inside the tenant.
     *
     * Public so the employee form can check before submitting rather than
     * after a round trip.
     */
    public function emailAvailable(string $email): bool
    {
        return ! User::where('email', Str::lower(trim($email)))->exists();
    }

    /**
     * @throws ValidationException
     */
    private function assertEmailAvailable(string $email): void
    {
        if ($this->emailAvailable($email)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'That email address is already used by another account.',
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function roleIds(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        // De-duplicated first: a select posting the same role twice is not a
        // missing role, and comparing raw counts would report it as one.
        $wanted = array_values(array_unique($slugs));
        $ids = Role::whereIn('slug', $wanted)->pluck('id')->all();

        if (count($ids) !== count($wanted)) {
            throw ValidationException::withMessages([
                'roles' => 'One or more of the selected roles does not exist.',
            ]);
        }

        return $ids;
    }

    /**
     * Mirror the new user into the central login-routing index.
     *
     * Without this row the account exists in the tenant database and can never
     * authenticate, because `AuthController::loginIsolated` resolves a tenant
     * from this index rather than by scanning every tenant database.
     *
     * The routing model is `CentralConnection`, so this is the cross-connection
     * write the class docblock is about.
     */
    private function writeRoutingRow(User $user, string $email): void
    {
        $tenantId = $this->tenantContext->currentId();

        if ($tenantId === null) {
            // No tenant context means a seeder, a console command or a test
            // driving the service directly. There is no tenant to route to, and
            // inventing one would point the index at the wrong database. The
            // user is still created, so the caller must not assume the row
            // exists when there was no context to route from.
            return;
        }

        TenantUserRouting::updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => $email],
            ['user_id' => (int) $user->id, 'name' => $user->name],
        );
    }
}
