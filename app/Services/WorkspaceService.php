<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceService
{
    public function __construct(
        private readonly TenantLimits $limits,
    ) {}

    public function listFor(User $user): Collection
    {
        $query = Workspace::withCount(['members', 'projects', 'labels'])
            ->with(['members' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('name');

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->get();
    }

    public function create(array $data, User $creator): Workspace
    {
        $this->limits->assertQuota('workspaces');

        $slug = $data['slug'] ?? Str::slug($data['name']);
        $slug = $this->uniqueSlug($slug);

        $workspace = Workspace::create([
            'created_by' => $creator->id,
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'default_assignee_id' => $data['default_assignee_id'] ?? null,
            'settings' => $data['settings'] ?? null,
        ]);

        $workspace->members()->attach($creator->id, [
            'role' => WorkspaceMemberRole::Owner->value,
            'added_by' => $creator->id,
        ]);

        return $workspace;
    }

    public function update(Workspace $workspace, array $data): Workspace
    {
        $workspace->update([
            'name' => $data['name'],
            'description' => array_key_exists('description', $data) ? $data['description'] : $workspace->description,
            'icon' => array_key_exists('icon', $data) ? $data['icon'] : $workspace->icon,
            'color' => array_key_exists('color', $data) ? $data['color'] : $workspace->color,
            'timezone' => array_key_exists('timezone', $data) ? $data['timezone'] : $workspace->timezone,
            'default_assignee_id' => array_key_exists('default_assignee_id', $data) ? $data['default_assignee_id'] : $workspace->default_assignee_id,
            'settings' => array_key_exists('settings', $data) ? $data['settings'] : $workspace->settings,
        ]);

        return $workspace;
    }

    public function archive(Workspace $workspace): Workspace
    {
        $workspace->update(['archived_at' => now()]);

        return $workspace;
    }

    public function restore(Workspace $workspace): Workspace
    {
        $workspace->update(['archived_at' => null]);

        return $workspace;
    }

    public function delete(Workspace $workspace): void
    {
        if ($workspace->projects()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Cannot delete a workspace that still contains projects. Archive it instead.',
            ]);
        }

        $workspace->delete();
    }

    public function addMember(Workspace $workspace, int $userId, WorkspaceMemberRole $role, User $actor): void
    {
        $user = User::find($userId);

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected user does not belong to this tenant.',
            ]);
        }

        if ($workspace->isMember($user)) {
            throw ValidationException::withMessages([
                'user_id' => 'This user is already a member of the workspace.',
            ]);
        }

        $workspace->members()->attach($userId, [
            'role' => $role->value,
            'added_by' => $actor->id,
        ]);
    }

    public function changeMemberRole(Workspace $workspace, User $member, WorkspaceMemberRole $role, User $actor): void
    {
        $current = $workspace->memberRole($member);

        if ($current === null) {
            throw ValidationException::withMessages([
                'form' => 'This user is not a member of the workspace.',
            ]);
        }

        if ($current === WorkspaceMemberRole::Owner && $role !== WorkspaceMemberRole::Owner) {
            $this->ensureNotOnlyOwner($workspace, $member);
        }

        $workspace->members()->updateExistingPivot($member->id, [
            'role' => $role->value,
            'added_by' => $actor->id,
        ]);
    }

    public function removeMember(Workspace $workspace, User $member, User $actor): void
    {
        if ($workspace->memberRole($member) === null) {
            throw ValidationException::withMessages([
                'form' => 'This user is not a member of the workspace.',
            ]);
        }

        $this->ensureNotOnlyOwner($workspace, $member);

        // Project membership is only valid inside workspace membership (adding a
        // project member requires it), so losing the workspace must also lose every
        // project of that workspace — otherwise the user keeps moving tasks,
        // commenting and uploading there, and the projects stay visible to them.
        DB::transaction(function () use ($workspace, $member): void {
            DB::table('project_members')
                ->where('user_id', $member->id)
                ->whereIn('project_id', $workspace->projects()->select('projects.id'))
                ->delete();

            $workspace->members()->detach($member->id);
        });
    }

    private function uniqueSlug(string $slug): string
    {
        $base = $slug;
        $candidate = $base;
        $i = 1;

        while (Workspace::where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }

    private function ensureNotOnlyOwner(Workspace $workspace, User $member): void
    {
        $ownerCount = $workspace->members()
            ->wherePivot('role', WorkspaceMemberRole::Owner->value)
            ->count();

        $memberIsOwner = $workspace->members()
            ->wherePivot('user_id', $member->id)
            ->wherePivot('role', WorkspaceMemberRole::Owner->value)
            ->exists();

        if ($memberIsOwner && $ownerCount <= 1) {
            throw ValidationException::withMessages([
                'form' => 'A workspace must keep at least one owner.',
            ]);
        }
    }
}
