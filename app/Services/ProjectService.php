<?php

namespace App\Services;

use App\Enums\TaskStatusCategory;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(
        private readonly TenantLimits $limits,
    ) {}

    public function listFor(Workspace $workspace, User $user): Collection
    {
        $query = Project::where('workspace_id', $workspace->id)
            ->with('workspace')
            ->with(['members' => fn ($q) => $q->where('user_id', $user->id)])
            ->withCount(['members', 'tasks', 'statuses'])
            ->orderBy('name');

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->get();
    }

    public function listAll(User $user): Collection
    {
        $query = Project::query()
            ->with('workspace')
            ->with(['members' => fn ($q) => $q->where('user_id', $user->id)])
            ->withCount(['members', 'tasks', 'statuses'])
            ->orderBy('name');

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->get();
    }

    public function create(Workspace $workspace, array $data, User $creator): Project
    {
        $this->limits->assertQuota('projects');

        $key = $this->uniqueProjectKey($data['key'] ?? $this->suggestKey($data['name']));

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'lead_user_id' => $creator->id,
            'name' => $data['name'],
            'key' => $key,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $this->seedDefaultStatuses($project);

        $leadRole = ProjectRole::where('slug', 'lead')->firstOrFail();
        $project->members()->attach($creator->id, [
            'project_role_id' => $leadRole->id,
            'added_by' => $creator->id,
        ]);

        return $project;
    }

    public function update(Project $project, array $data): Project
    {
        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? $project->description,
            'icon' => $data['icon'] ?? $project->icon,
            'start_date' => $data['start_date'] ?? $project->start_date,
            'due_date' => $data['due_date'] ?? $project->due_date,
        ]);

        return $project;
    }

    public function archive(Project $project): Project
    {
        $project->update(['archived_at' => now()]);

        return $project;
    }

    public function restore(Project $project): Project
    {
        $project->update(['archived_at' => null]);

        return $project;
    }

    public function delete(Project $project): void
    {
        if ($project->tasks()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Cannot delete a project that still contains tasks. Archive it instead.',
            ]);
        }

        $project->delete();
    }

    public function addMember(Project $project, int $userId, int $roleId, User $actor): void
    {
        $user = User::find($userId);
        $role = $this->findRole($project, $roleId);

        if ($user === null) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected user does not belong to this tenant.',
            ]);
        }

        if (! $project->workspace->isMember($user)) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected user must be a member of the workspace first.',
            ]);
        }

        if ($project->isMember($user)) {
            throw ValidationException::withMessages([
                'user_id' => 'This user is already a member of the project.',
            ]);
        }

        $project->members()->attach($user->id, [
            'project_role_id' => $role->id,
            'added_by' => $actor->id,
        ]);
    }

    public function changeMemberRole(Project $project, User $member, int $roleId, User $actor): void
    {
        if (! $project->isMember($member)) {
            throw ValidationException::withMessages([
                'form' => 'This user is not a member of the project.',
            ]);
        }

        $role = $this->findRole($project, $roleId);

        $project->members()->updateExistingPivot($member->id, [
            'project_role_id' => $role->id,
            'added_by' => $actor->id,
        ]);
    }

    public function removeMember(Project $project, User $member, User $actor): void
    {
        if (! $project->isMember($member)) {
            throw ValidationException::withMessages([
                'form' => 'This user is not a member of the project.',
            ]);
        }

        if ($project->members()->count() <= 1) {
            throw ValidationException::withMessages([
                'form' => 'A project must keep at least one member.',
            ]);
        }

        $project->members()->detach($member->id);
    }

    private function findRole(Project $project, int $roleId): ProjectRole
    {
        $role = ProjectRole::find($roleId);

        if ($role === null) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected project role is invalid.',
            ]);
        }

        return $role;
    }

    private function seedDefaultStatuses(Project $project): void
    {
        $position = 0;

        foreach (config('task_statuses.statuses') as $status) {
            $position++;

            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => TaskStatusCategory::from($status['category']),
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }
    }

    public function addStatus(Project $project, array $data): TaskStatus
    {
        $position = $data['position'] ?? ($project->statuses()->max('position') + 1);
        $slug = $this->uniqueStatusSlug($project, Str::slug($data['name']));

        $status = TaskStatus::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'slug' => $slug,
            'category' => TaskStatusCategory::from($data['category']),
            'position' => $position,
            'color' => $data['color'] ?? null,
            'is_default' => $data['is_default'] ?? false,
            'is_done' => $data['category'] === 'done',
        ]);

        $this->normalizePositions($project);

        return $status;
    }

    public function updateStatus(TaskStatus $status, array $data): TaskStatus
    {
        $status->update([
            'name' => $data['name'] ?? $status->name,
            'category' => TaskStatusCategory::from($data['category'] ?? $status->category->value),
            'color' => $data['color'] ?? $status->color,
            'is_done' => $data['is_done'] ?? ($data['category'] ?? $status->category->value) === 'done',
        ]);

        if (isset($data['position'])) {
            $this->reposition($status, $data['position']);
        }

        return $status->fresh();
    }

    public function deleteStatus(TaskStatus $status): void
    {
        $project = $status->project;

        if ($project->statuses()->count() <= 1) {
            throw ValidationException::withMessages([
                'form' => 'A project must keep at least one status.',
            ]);
        }

        if ($status->tasks()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Cannot delete a status that still has tasks. Move or delete them first.',
            ]);
        }

        $status->delete();
        $this->normalizePositions($project);
    }

    private function reposition(TaskStatus $status, int $position): void
    {
        $project = $status->project;

        $ids = $project->statuses()
            ->whereKeyNot($status->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $index = max(0, min($position - 1, $ids->count()));

        $ids = $ids->slice(0, $index)
            ->concat([$status->id])
            ->concat($ids->slice($index))
            ->values();

        $ids->each(function (int $id, int $i) {
            TaskStatus::whereKey($id)->update(['position' => $i + 1]);
        });

        $this->normalizePositions($project);
    }

    private function normalizePositions(Project $project): void
    {
        $position = 0;

        $project->statuses()->orderBy('position')->orderBy('id')->get()
            ->each(function (TaskStatus $status) use (&$position) {
                $status->update(['position' => ++$position]);
            });
    }

    private function uniqueStatusSlug(Project $project, string $slug): string
    {
        $base = $slug;
        $candidate = $base;
        $i = 1;

        while ($project->statuses()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }

    private function suggestKey(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        if (count($words) === 1) {
            return Str::upper(Str::substr($words[0], 0, 4));
        }

        return collect($words)
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->take(4)
            ->implode('');
    }

    private function uniqueProjectKey(string $key): string
    {
        $base = Str::upper(Str::limit(Str::slug($key, '-'), 16, ''));
        $candidate = $base;
        $i = 1;

        while (Project::where('key', $candidate)->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }
}
