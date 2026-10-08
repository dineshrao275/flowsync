<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'comment' => fake()->sentence(12),
        ];
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn () => ['task_id' => $task->id]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function replyTo(Comment $parent): static
    {
        return $this->state(fn () => [
            'task_id' => $parent->task_id,
            'parent_id' => $parent->id,
        ]);
    }
}
