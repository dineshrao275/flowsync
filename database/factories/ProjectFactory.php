<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);
        $key = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
        if (strlen($key) < 2) {
            $key = 'PRJ';
        }

        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'lead_user_id' => User::factory(),
            'name' => ucfirst($name),
            'key' => $key.rand(10, 99),
            'description' => fake()->sentence(),
            'last_task_sequence' => 0,
            'archived_at' => null,
        ];
    }

    public function withKey(string $key): static
    {
        return $this->state(fn () => ['key' => $key]);
    }

    /**
     * Seed the default workflow statuses from the catalog.
     */
    public function withStatuses(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $position = 0;

            foreach (config('task_statuses.statuses') as $status) {
                $position++;

                TaskStatus::factory()->forProject($project)->fromCatalog($status, $position)->create();
            }
        });
    }

    /**
     * Attach members cycling lead/developer/viewer, first user leads.
     *
     * @param  iterable<User>  $users
     */
    public function withMembers(iterable $users, int $addedBy): static
    {
        return $this->afterCreating(function (Project $project) use ($users, $addedBy): void {
            $roles = ProjectRole::whereIn('slug', ['lead', 'developer', 'viewer'])->pluck('id', 'slug');

            foreach (array_values([...$users]) as $i => $user) {
                $slug = $i === 0 ? 'lead' : ($i % 2 === 1 ? 'developer' : 'viewer');

                $project->members()->attach($user->id, [
                    'project_role_id' => $roles->get($slug) ?? $roles->first(),
                    'added_by' => $addedBy,
                ]);
            }
        });
    }
}
