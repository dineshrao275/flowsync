<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Models\Role;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Services\TenantLimits;
use App\Support\GrantCeiling;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use AuditsTenantAdminActions;

    /**
     * The role a user must hold to be eligible as the tenant's default user.
     */
    private const DEFAULT_USER_ROLE = 'admin';

    public function __construct(
        private readonly TenantLimits $limits,
        private readonly TenantContext $tenantContext,
        private readonly EmployeeBackfill $backfill,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->limits->assertQuota('users');

        // Normalize before validating, matching `RegisterController` and the
        // central routing index. Login lower-cases the submitted address before
        // resolving the tenant, and `Auth::attempt` then matches the tenant DB
        // row case-sensitively — so a stored `New.Hire@Acme.Test` would only be
        // reachable by typing that exact casing back in.
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        // Before the row exists: a refused grant must not leave a half-made login.
        $roles = Role::whereIn('slug', $data['roles'])->pluck('id');
        GrantCeiling::assertCanAssignRoles($request->user(), $roles, collect());

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->roles()->sync($roles);

        $this->auditTenantAdmin($request, 'user.created', 'users', $user->id, null, [
            'roles' => $user->roles()->pluck('slug')->sort()->values()->all(),
        ]);

        // The central login-routing index is what resolves this email to a
        // tenant database at login time; without this row the account exists
        // but can never authenticate (see `AuthController::loginIsolated`).
        TenantUserRouting::updateOrCreate(
            [
                'tenant_id' => $this->tenantContext->currentId(),
                'email' => $data['email'],
            ],
            [
                'user_id' => (int) $user->id,
                'name' => $user->name,
            ]
        );

        // Every login owns an employment record: self-service reads scope
        // through it, so an account without one renders as broken HRMS.
        $this->backfill->linkFor($user);

        return response()->json([
            'message' => 'User created.',
            'user' => $this->present($user->load('roles')),
        ], 201);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $roles = Role::whereIn('slug', $data['roles'])->pluck('id');

        GrantCeiling::assertCanAssignRoles($request->user(), $roles, $user->roles()->pluck('roles.id'));

        // The default user must stay an admin, otherwise the tenant loses the
        // protected break-glass account this tenant guarantees.
        if ($user->is_default && ! $roles->contains(Role::where('slug', self::DEFAULT_USER_ROLE)->value('id'))) {
            throw ValidationException::withMessages([
                'roles' => 'The default user must keep the '.self::DEFAULT_USER_ROLE.' role. Shift the default to another admin first.',
            ]);
        }

        $before = ['roles' => $user->roles()->pluck('slug')->sort()->values()->all()];

        $user->roles()->sync($roles);

        $after = ['roles' => $user->roles()->pluck('slug')->sort()->values()->all()];
        if ($before !== $after) {
            $this->auditTenantAdmin($request, 'user.roles_changed', 'users', $user->id, $before, $after);
        }

        return response()->json([
            'message' => 'Roles updated.',
            'user' => $this->present($user->load('roles')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'form' => 'You cannot delete your own account.',
            ]);
        }

        $id = $user->id;
        $email = $user->email;

        // The model refuses to delete the default user (defence in depth for
        // any code path, not just this endpoint).
        $user->delete();

        // Drop the central login-routing row, otherwise the deleted email keeps
        // routing to this tenant on the next login attempt.
        TenantUserRouting::where('tenant_id', $this->tenantContext->currentId())
            ->where('email', $email)
            ->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    /**
     * Shift the tenant's default user. Callable by tenant admins (via
     * `users.manage`) and by super admins; the new default must be an admin.
     */
    public function makeDefault(Request $request, User $user): JsonResponse
    {
        $user->load('roles');

        if (! $user->hasRole(self::DEFAULT_USER_ROLE)) {
            throw ValidationException::withMessages([
                'form' => 'The default user must be an '.self::DEFAULT_USER_ROLE.'. Give '.ucfirst($user->name).' the '.self::DEFAULT_USER_ROLE.' role first.',
            ]);
        }

        DB::transaction(function () use ($user) {
            // Query builder, not `$model->update()`: a shift onto the user who is
            // already the default would be skipped by Eloquent's dirty check and
            // leave the tenant with no default user at all.
            User::query()
                ->where('is_default', true)
                ->where('id', '!=', $user->getKey())
                ->update(['is_default' => false]);

            User::query()->whereKey($user->getKey())->update(['is_default' => true]);
        });

        return response()->json([
            'message' => ucfirst($user->name).' is now the default user for this tenant.',
            'user' => $this->present($user->fresh('roles')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('slug'),
            'is_default' => (bool) $user->is_default,
        ];
    }
}
