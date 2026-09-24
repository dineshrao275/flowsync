<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin platform accounts (central `users` with is_super_admin). List +
 * create only — the current session is the account for any edits.
 */
class SystemUsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

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

        return response()->json(['users' => $users->items(), 'pagination' => $users->toArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

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
