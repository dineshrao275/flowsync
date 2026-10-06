<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'created_by' => User::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'icon' => null,
            'timezone' => 'UTC',
            'archived_at' => null,
        ];
    }

    /**
     * Attach members cycling owner/admin/member, first user owns.
     *
     * @param  iterable<User>  $users
     */
    public function withMembers(iterable $users, int $addedBy): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($users, $addedBy): void {
            foreach (array_values([...$users]) as $i => $user) {
                $workspace->members()->attach($user->id, [
                    'role' => $i === 0 ? 'owner' : ($i === 1 ? 'admin' : 'member'),
                    'added_by' => $addedBy,
                ]);
            }
        });
    }

    public function withSlug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
