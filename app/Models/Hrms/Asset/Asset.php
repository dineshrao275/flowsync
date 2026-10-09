<?php

namespace App\Models\Hrms\Asset;

use App\Enums\Hrms\AssetCondition;
use App\Enums\Hrms\AssetStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asset/HRMS — one tracked item in the register.
 *
 * The number is server-stamped (`AST-000001`); assignment state (`status`,
 * `assigned_to_employee_id`) lives here for the at-a-glance read while the
 * handover ledger lives in `asset_assignments`. Soft-deleted, never hard —
 * a retired laptop keeps its history, and the ledger rows point at it.
 */
class Asset extends Model
{
    use SoftDeletes;

    protected $table = 'assets';

    protected $fillable = [
        'asset_code',
        'name',
        'category_id',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'purchase_value',
        'vendor',
        'invoice_document_id',
        'warranty_ends_at',
        'condition',
        'status',
        'assigned_to_employee_id',
        'assigned_at',
        'returned_at',
        'location_id',
        'notes',
        'created_by',
        'replaced_by_asset_id',
        'replacement_reason',
        'replaced_at',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'purchase_date' => 'date',
            'purchase_value' => 'decimal:2',
            'invoice_document_id' => 'integer',
            'warranty_ends_at' => 'date',
            'condition' => AssetCondition::class,
            'status' => AssetStatus::class,
            'assigned_to_employee_id' => 'integer',
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
            'location_id' => 'integer',
            'created_by' => 'integer',
            'replaced_by_asset_id' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'invoice_document_id');
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'asset_id');
    }

    /** @return HasMany<AssetMaintenance, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class, 'asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
