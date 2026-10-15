<?php

namespace App\Models\Hrms\CompOff;

use App\Enums\Hrms\CompOffSource;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CompOff/HRMS — banked time for working when others rest.
 *
 * Append-only: rows are never edited, only expired (the row stays past
 * `expiry_date`) or spent through an approved request. The balance is
 * derived from these rows, never stored — see `CompOffService`.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property CompOffSource $source_type
 * @property int $minutes
 * @property Carbon|null $expiry_date
 */
class CompOffCredit extends Model
{
    protected $table = 'comp_off_credits';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'work_date',
        'source_type',
        'minutes',
        'expiry_date',
        'note',
        'actor_user_id',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => 'date',
            'source_type' => CompOffSource::class,
            'minutes' => 'integer',
            'expiry_date' => 'date',
            'actor_user_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isExpired(Carbon|string|null $asOf = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $asOf = $asOf instanceof Carbon ? $asOf->toDateString() : (string) ($asOf ?? today()->toDateString());

        return $this->expiry_date->toDateString() < $asOf;
    }
}
