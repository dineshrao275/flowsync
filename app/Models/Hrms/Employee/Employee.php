<?php

namespace App\Models\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Concerns\CentralConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Employee/HRMS — the person record every other HRMS phase hangs off.
 *
 * Lives in the **tenant** database and deliberately does *not* use
 * {@see CentralConnection}: the tenant database is the
 * isolation boundary (Phase 13), so the default connection is already correct.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $employee_code
 * @property string $name
 * @property string|null $preferred_name
 * @property string|null $personal_email
 * @property string|null $phone
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $marital_status
 * @property string|null $nationality
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $emergency_contact_relation
 * @property string|null $photo_path
 * @property Carbon|null $joining_date
 * @property Carbon|null $probation_end_date
 * @property Carbon|null $confirmation_date
 * @property int|null $employment_type_id
 * @property string|null $designation
 * @property int|null $manager_id
 * @property WorkMode $work_mode
 * @property EmployeeStatus $status
 * @property Carbon|null $exit_date
 * @property string|null $exited_reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read EmploymentType|null $employmentType
 * @property-read Employee|null $manager
 * @property-read Collection<int, Employee> $reports
 * @property-read Collection<int, EmployeeStatusHistory> $statusHistory
 */
class Employee extends Model
{
    use SoftDeletes;

    protected $table = 'employees';

    protected $fillable = [
        'user_id',
        'employee_code',
        'name',
        'preferred_name',
        'personal_email',
        'phone',
        'date_of_birth',
        'gender',
        'marital_status',
        'nationality',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'photo_path',
        'joining_date',
        'probation_end_date',
        'confirmation_date',
        'employment_type_id',
        'designation',
        'manager_id',
        'work_mode',
        'status',
        'exit_date',
        'exited_reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_mode' => WorkMode::class,
            'status' => EmployeeStatus::class,
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
            'exit_date' => 'date',
            'user_id' => 'integer',
            'employment_type_id' => 'integer',
            'manager_id' => 'integer',
        ];
    }

    /**
     * The login account, when this employee has one.
     *
     * Null is normal, not broken: service accounts have no employee record, and
     * contractors and field staff have an employee record with no login.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->orderByDesc('created_at');
    }

    /** @param  Builder<Employee>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EmployeeStatus::Active->value);
    }

    /** @param  Builder<Employee>  $query */
    public function scopeOnNotice(Builder $query): void
    {
        $query->where('status', EmployeeStatus::OnNotice->value);
    }

    /**
     * Everyone still on the payroll.
     *
     * `status` is an indexed column and this is the single most common filter
     * (attendance rosters, leave balances, headcount), so it gets its own scope
     * rather than every caller writing the `whereIn` itself and getting the
     * offboarding set subtly wrong.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeEmployed(Builder $query): void
    {
        $query->whereIn('status', array_values(array_filter(
            EmployeeStatus::cases(),
            fn (EmployeeStatus $status) => $status->isEmployed(),
        )));
    }

    /** @param  Builder<Employee>  $query */
    public function scopeManagedBy(Builder $query, Employee $manager): void
    {
        $query->where('manager_id', $manager->id);
    }

    /**
     * The name to show in a roster or a payslip: the preferred name if the
     * employee set one, otherwise their legal name.
     */
    public function displayName(): string
    {
        return $this->preferred_name ?: $this->name;
    }

    /**
     * The years of service that count for a service-length calculation, or null
     * when the employee has not joined yet.
     */
    public function tenureOn(?Carbon $asOf = null): ?int
    {
        if ($this->joining_date === null) {
            return null;
        }

        // abs() because Carbon 2's diffInYears is signed and a joining date in
        // the past yields a *negative* span — a -2 year tenure is not a
        // service-length calculation anyone can use. Cast because it returns a
        // float (6.575 years), and letting the `?int` return type coerce it
        // deprecates on PHP 8.3 and rounds nothing in particular.
        return (int) abs(($asOf ?? now())->startOfDay()->diffInYears($this->joining_date->startOfDay()));
    }
}
