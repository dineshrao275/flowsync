<?php

namespace Database\Factories;

use App\Models\Project;
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
}
