<?php

namespace Database\Factories\Hrms;

use App\Enums\Hrms\PayrollRunStatus;
use App\Models\Hrms\Payroll\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'period_year' => (int) date('Y'),
            'period_month' => (int) date('m'),
            'pay_period_start' => now()->startOfMonth()->toDateString(),
            'pay_period_end' => now()->endOfMonth()->toDateString(),
            'pay_date' => now()->endOfMonth()->toDateString(),
            'status' => PayrollRunStatus::Draft,
            'employee_count' => fake()->numberBetween(5, 50),
            'totals' => [
                'gross' => 150000.0,
                'deductions' => 15000.0,
                'net' => 135000.0,
            ],
        ];
    }
}
