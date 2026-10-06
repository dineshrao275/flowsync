<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'key' => 'TSK-'.fake()->unique()->numberBetween(1, 99999),
            'sequence' => fake()->numberBetween(1, 1000),
            'status_id' => 1,
            'priority_id' => 1,
            'position' => 1,
            'parent_id' => null,
            'due_date' => null,
            'completed_at' => null,
        ];
    }
}
