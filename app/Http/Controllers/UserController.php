<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\TenantLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly TenantLimits $limits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('slug'),
            ]);

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->limits->assertQuota('users');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $roles = Role::whereIn('slug', $data['roles'])->pluck('id');
        $user->roles()->sync($roles);

        return response()->json([
            'message' => 'User created.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles()->pluck('slug'),
            ],
        ], 201);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $roles = Role::whereIn('slug', $data['roles'])->pluck('id');
        $user->roles()->sync($roles);

        return response()->json([
            'message' => 'Roles updated.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles()->pluck('slug'),
            ],
        ]);
    }
}
