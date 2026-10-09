<?php

namespace App\Http\Controllers;

use App\Models\PlatformRole;
use App\Models\SystemUser;
use App\Services\PlatformAudit;
use App\Services\Security\PlatformAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persona catalog and assignment for platform accounts (P8.3). Gated by the route map (platform_users.*). */
class PlatformRoleController extends Controller
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => PlatformRole::query()->with('permissions:id,slug')->orderBy('name')->get()->map(fn (PlatformRole $r) => [
                'slug' => $r->slug,
                'name' => $r->name,
                'description' => $r->description,
                'permissions' => $r->permissions->pluck('slug')->sort()->values(),
            ]),
            'permissions' => config('platform_access.permissions'),
        ]);
    }

    /** Replace the persona set; an empty list makes the account unrestricted break-glass again. */
    public function assign(Request $request, SystemUser $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['string']]);

        if (! $user->is_super_admin) {
            abort(404);
        }

        $roles = PlatformRole::query()->whereIn('slug', $data['roles'])->get();
        if ($roles->count() !== count(array_unique($data['roles']))) {
            throw ValidationException::withMessages(['roles' => 'Unknown platform role.']);
        }

        $before = $this->access->roleSlugsOf($user);

        // Narrowing yourself or the last unrestricted admin would lock the platform out of break-glass.
        if ($roles->isNotEmpty() && $this->unrestrictedAdminsExcluding($user) === 0) {
            throw ValidationException::withMessages(['form' => 'At least one platform admin must stay unrestricted (break-glass).']);
        }

        DB::connection($user->getConnectionName())->transaction(fn () => $this->sync($user, $roles->pluck('id')->all()));
        $this->access->forget();

        app(PlatformAudit::class)->diff($request, 'platform.roles_assigned', 'users', $user->id,
            ['roles' => $before], ['roles' => $this->access->roleSlugsOf($user)], ['email' => $user->email]);

        return response()->json(['message' => 'Platform roles saved.', 'platform_roles' => $this->access->roleSlugsOf($user)]);
    }

    /** @param list<int> $roleIds */
    private function sync(SystemUser $user, array $roleIds): void
    {
        $connection = DB::connection($user->getConnectionName());
        $connection->table('platform_user_role')->where('user_id', $user->id)->delete();
        foreach ($roleIds as $id) {
            $connection->table('platform_user_role')->insert([
                'user_id' => $user->id, 'platform_role_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function unrestrictedAdminsExcluding(SystemUser $except): int
    {
        return SystemUser::query()->where('is_super_admin', true)->where('id', '!=', $except->id)->get()
            ->filter(fn (SystemUser $u) => $this->access->isUnrestricted($u))->count();
    }
}
