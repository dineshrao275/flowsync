<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'reporter_id' => null,
            'assignee_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            // Standalone placeholder: uniqueness is (project_id, key), so
            // seeders/tests building real projects must set an explicit key
            // via withKey() instead of relying on this random suffix.
            'key' => 'TMP-'.Str::upper(Str::random(6)),
            'sequence' => 1,
            'status_id' => null,
            'priority_id' => null,
            'position' => 1,
            'parent_id' => null,
            'due_date' => null,
            'completed_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
        ]);
    }

    public function withKey(string $key, int $sequence): static
    {
        return $this->state(fn () => ['key' => $key, 'sequence' => $sequence]);
    }

    public function forStatus(TaskStatus $status): static
    {
        return $this->state(fn () => ['status_id' => $status->id]);
    }

    public function assignedTo(?User $user): static
    {
        return $this->state(fn () => ['assignee_id' => $user?->id]);
    }

    public function completed(?string $at = null): static
    {
        return $this->state(fn () => ['completed_at' => $at ?? now()->toDateTimeString()]);
    }
}
