<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $members = $project->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json(['members' => $members]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer', 'exists:project_roles,id'],
        ]);

        $this->service->addMember(
            $project,
            $data['user_id'],
            $data['role_id'],
            $request->user(),
        );

        return response()->json(['message' => 'Member added.'], 201);
    }

    public function update(Request $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:project_roles,id'],
        ]);

        $this->service->changeMemberRole($project, $user, $data['role_id'], $request->user());

        return response()->json(['message' => 'Member role updated.']);
    }

    public function destroy(Request $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $this->service->removeMember($project, $user, $request->user());

        return response()->json(['message' => 'Member removed.']);
    }

    private function present(User $user): array
    {
        $role = $user->pivot->project_role_id ? ProjectRole::find($user->pivot->project_role_id) : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role ? ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name] : null,
        ];
    }
}
