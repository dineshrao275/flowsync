<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemUserIndexRequest;
use App\Http\Requests\SystemUserStoreRequest;
use App\Models\SystemUser;
use App\Services\PlatformAudit;
use App\Support\Like;
use Illuminate\Http\JsonResponse;

/**
 * Super-admin platform accounts (central `users` with is_super_admin). List +
 * create only — the current session is the account for any edits.
 */
class SystemUsersController extends Controller
{
    public function index(SystemUserIndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $query = SystemUser::query();

        if (! empty($data['q'])) {
            Like::any($query, ['name', 'email'], $data['q']);
        }

        $users = $query->latest()->paginate($data['per_page'] ?? 15)
            ->withQueryString()
            ->through(fn (SystemUser $user) => $user->only(['id', 'name', 'email', 'is_super_admin', 'created_at']));

        return response()->json([
            'users' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(SystemUserStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = SystemUser::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_super_admin' => true,
        ]);

        app(PlatformAudit::class)->diff(
            $request,
            'system.user_created',
            'users',
            $user->id,
            null,
            ['name' => $user->name, 'email' => $user->email],
        );

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'is_super_admin', 'created_at'])], 201);
    }
}
