<?php

namespace App\Models\Hrms\Analytics;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Analytics/HRMS — one scheduled digest definition.
 *
 * What to build (`definition` names sections and filters), how often, and
 * who gets it (user ids and/or role slugs, resolved at send time so the
 * digest follows the role as people join and leave it). `next_run_at` is
 * the only scheduling state: sending advances it, so a digest never
 * re-sends by construction rather than by a sent-flag ledger.
 */
class ReportSchedule extends Model
{
    protected $table = 'hrms_report_schedules';

    protected $fillable = [
        'name',
        'slug',
        'definition',
        'cadence',
        'recipients',
        'last_run_at',
        'next_run_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'recipients' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function scopeDue($query): void
    {
        $query->where(function ($nested): void {
            $nested->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
