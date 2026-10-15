<?php

namespace Database\Factories\Hrms;

use App\Enums\Hrms\PerformanceCycleStage;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PerformanceCycle>
 */
class PerformanceCycleFactory extends Factory
{
    protected $model = PerformanceCycle::class;

    public function definition(): array
    {
        $name = 'Q'.fake()->numberBetween(1, 4).' Review '.date('Y');

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'period_start' => now()->startOfQuarter()->toDateString(),
            'period_end' => now()->endOfQuarter()->toDateString(),
            'stage' => PerformanceCycleStage::GoalSetting,
            'anonymity' => 'none',
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
