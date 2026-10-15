<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLog>
 */
class WorkLogFactory extends Factory
{
    protected $model = WorkLog::class;

    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-30 days', 'now');
        $minutes = fake()->numberBetween(30, 480);
        $ended = (clone $started)->modify("+{$minutes} minutes");

        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'started_at' => $started,
            'ended_at' => $ended,
            'duration_minutes' => $minutes,
            'description' => fake()->sentence(6),
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

    public function on(string $date, string $from = '09:00:00', int $minutes = 120): static
    {
        return $this->state(fn () => [
            'started_at' => "{$date} {$from}",
            'ended_at' => date('Y-m-d H:i:s', strtotime("{$date} {$from} +{$minutes} minutes")),
            'duration_minutes' => $minutes,
        ]);
    }
}
