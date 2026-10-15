<?php

namespace App\Models\Hrms\Asset;

use App\Enums\Hrms\AssetMaintenanceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asset/HRMS — one repair, service, upgrade or inspection.
 *
 * Append-only: closing the loop is a second row (or a status move on the
 * asset back to service), never an edit that rewrites what the repair
 * cost. `next_due_at` schedules the follow-up an inspection found.
 */
class AssetMaintenance extends Model
{
    protected $table = 'asset_maintenance';

    protected $fillable = [
        'asset_id',
        'type',
        'description',
        'performed_by',
        'cost',
        'performed_at',
        'next_due_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'type' => AssetMaintenanceType::class,
            'cost' => 'decimal:2',
            'performed_at' => 'date',
            'next_due_at' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
