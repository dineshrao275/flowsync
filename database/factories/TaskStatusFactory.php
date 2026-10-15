<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatus>
 */
class TaskStatusFactory extends Factory
{
    protected $model = TaskStatus::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => ucfirst(fake()->word()),
            'slug' => fake()->slug(1),
            'category' => 'todo',
            'position' => 1,
            'color' => '#94a3b8',
            'is_default' => false,
            'is_done' => false,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /**
     * One status row per entry of the default catalog, positioned in order.
     *
     * @param  array<string, mixed>  $config
     */
    public function fromCatalog(array $config, int $position): static
    {
        return $this->state(fn () => [
            'name' => $config['name'],
            'slug' => $config['slug'],
            'category' => $config['category'],
            'position' => $position,
            'color' => $config['color'] ?? null,
            'is_default' => $config['is_default'] ?? false,
            'is_done' => $config['is_done'] ?? false,
        ]);
    }
}
