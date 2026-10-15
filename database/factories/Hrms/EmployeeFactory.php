<?php

namespace Database\Factories\Hrms;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->name(),
            'personal_email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'work_mode' => WorkMode::Remote,
            'status' => EmployeeStatus::Active,
            'joining_date' => now()->subMonths(6),
        ];
    }
}
