<?php

namespace Database\Factories\Hrms;

use App\Enums\Hrms\LeaveHalf;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $from = now()->addDays(5);
        $to = $from->copy()->addDays(2);

        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => 1,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'from_half' => LeaveHalf::FirstHalf,
            'to_half' => LeaveHalf::SecondHalf,
            'total_days' => 3.0,
            'reason' => fake()->sentence(),
            'status' => LeaveRequestStatus::Pending,
        ];
    }
}
