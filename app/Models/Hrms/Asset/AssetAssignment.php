<?php

namespace App\Models\Hrms\Asset;

use App\Enums\Hrms\AssetAssignmentStatus;
use App\Enums\Hrms\AssetCondition;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asset/HRMS — one handover of one asset to one person.
 *
 * Unique per (asset, employee, moment): re-issuing the same laptop to the
 * same person is a second row with a second timestamp, never an edit.
 * Acknowledgement is the employee's receipt signature; the return closes
 * the row with the condition it came back in.
 */
class AssetAssignment extends Model
{
    protected $table = 'asset_assignments';

    protected $fillable = [
        'asset_id',
        'employee_id',
        'assigned_by_user_id',
        'assigned_at',
        'condition_out',
        'acknowledged_at',
        'returned_at',
        'condition_in',
        'return_note',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'employee_id' => 'integer',
            'assigned_by_user_id' => 'integer',
            'assigned_at' => 'datetime',
            'condition_out' => AssetCondition::class,
            'acknowledged_at' => 'datetime',
            'returned_at' => 'datetime',
            'condition_in' => AssetCondition::class,
            'status' => AssetAssignmentStatus::class,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
