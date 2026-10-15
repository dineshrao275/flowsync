<?php

namespace Database\Factories\Hrms;

use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseClaim>
 */
class ExpenseClaimFactory extends Factory
{
    protected $model = ExpenseClaim::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'claim_number' => 'EXP-'.date('Y').'-'.fake()->unique()->numberBetween(100000, 999999),
            'claim_date' => now()->toDateString(),
            'period_year' => (int) date('Y'),
            'period_month' => (int) date('m'),
            'purpose' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'currency' => 'USD',
            'total_amount' => 250.00,
            'approved_amount' => null,
            'status' => ExpenseClaimStatus::Submitted,
        ];
    }
}
