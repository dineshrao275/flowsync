<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemUserIndexRequest;
use App\Http\Requests\SystemUserStoreRequest;
use App\Models\AuditLog;
use App\Models\SystemUser;
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
            $q = '%'.$data['q'].'%';
            $query->where(fn ($w) => $w
                ->where('name', 'like', $q)
                ->orWhere('email', 'like', $q));
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

        AuditLog::create([
            'subject_type' => 'users',
            'subject_id' => $user->id,
            'action' => 'system.user_created',
            'data' => ['name' => $user->name, 'email' => $user->email],
            'actor_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'is_super_admin', 'created_at'])], 201);
    }
}
