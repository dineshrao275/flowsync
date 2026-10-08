<?php

namespace App\Models\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Employee/HRMS — one link in a status transition.
 *
 * Append-only: `created_at` with no `updated_at`, because a status change is
 * never rewritten — correcting one is another row. The `employee_id` cascade is
 * the opposite of `employees.manager_id`, which nulls out: a history row has no
 * meaning without the record it describes, while an orphaned report is still a
 * person.
 *
 * @property int $id
 * @property int $employee_id
 * @property EmployeeStatus|null $from_status
 * @property EmployeeStatus $to_status
 * @property Carbon|null $effective_date
 * @property string|null $reason
 * @property string|null $note
 * @property int|null $actor_user_id
 * @property Carbon|null $created_at
 */
class EmployeeStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'employee_status_history';

    protected $fillable = [
        'employee_id',
        'from_status',
        'to_status',
        'effective_date',
        'reason',
        'note',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => EmployeeStatus::class,
            'to_status' => EmployeeStatus::class,
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user who made the change, if the account still exists.
     *
     * `nullOnDelete` on `actor_user_id` means this can legitimately be null: the
     * account was deleted. The row still reads correctly then — an unattributed
     * change is a fact, not a gap to hide — and it is why the presenter emits
     * `actor: null` rather than dropping the key.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
