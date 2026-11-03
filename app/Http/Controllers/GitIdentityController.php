<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitIdentityRequest;
use App\Models\GitIdentity;
use App\Models\User;
use App\Services\Integrations\Git\GitIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admins map GitHub logins to users so smart commits can act as them. */
class GitIdentityController extends Controller
{
    public function __construct(private readonly GitIdentityService $identities) {}

    public function index(): JsonResponse
    {
        $identities = $this->identities->list();
        $users = User::query()->whereIn('id', $identities->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return response()->json(['identities' => $identities->map(fn (GitIdentity $i) => [
            'id' => $i->id, 'login' => $i->login, 'user_id' => $i->user_id,
            'user' => isset($users[$i->user_id]) ? ['name' => $users[$i->user_id]->name, 'email' => $users[$i->user_id]->email] : null,
            'linked_at' => $i->linked_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(GitIdentityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $identity = $this->identities->map((int) $data['user_id'], $data['login'], $request->user(), $request);

        return response()->json(['message' => __('GitHub login mapped.'), 'identity' => ['id' => $identity->id, 'login' => $identity->login, 'user_id' => $identity->user_id]], 201);
    }

    public function destroy(Request $request, GitIdentity $identity): JsonResponse
    {
        $this->identities->unmap($identity, $request->user(), $request);

        return response()->json(['message' => __('GitHub login unmapped.')]);
    }
}
