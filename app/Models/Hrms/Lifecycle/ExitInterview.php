<?php

declare(strict_types=1);

namespace App\Models\Hrms\Lifecycle;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExitInterview extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const WAIVED = 'waived';

    protected $table = 'exit_interviews';

    protected $fillable = ['offboarding_case_id', 'employee_id', 'status', 'answers', 'recorded_by_user_id', 'completed_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'completed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class);
    }
}
