<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    public function __construct(private readonly WorkspaceService $service) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $members = $workspace->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
            ]);

        return response()->json(['members' => $members]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::enum(WorkspaceMemberRole::class)],
        ]);

        $this->service->addMember(
            $workspace,
            $data['user_id'],
            WorkspaceMemberRole::from($data['role']),
            $request->user(),
        );

        return response()->json(['message' => 'Member added.'], 201);
    }

    public function update(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $data = $request->validate([
            'role' => ['required', Rule::enum(WorkspaceMemberRole::class)],
        ]);

        $this->service->changeMemberRole(
            $workspace,
            $user,
            WorkspaceMemberRole::from($data['role']),
            $request->user(),
        );

        return response()->json(['message' => 'Member role updated.']);
    }

    public function destroy(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $this->service->removeMember($workspace, $user, $request->user());

        return response()->json(['message' => 'Member removed.']);
    }
}
