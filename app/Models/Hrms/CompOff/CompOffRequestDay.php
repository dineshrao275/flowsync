<?php

namespace App\Models\Hrms\CompOff;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CompOff/HRMS — one calendar date inside a redemption ask.
 *
 * Derived, never edited: the engine re-splits the range whenever the ask
 * changes. Week-offs stay in the table flagged and out of the total, the
 * same photograph rule as leave's split rows.
 */
class CompOffRequestDay extends Model
{
    protected $table = 'comp_off_request_days';

    public $timestamps = false;

    protected $fillable = [
        'comp_off_request_id',
        'date',
        'minutes',
    ];

    protected function casts(): array
    {
        return [
            'comp_off_request_id' => 'integer',
            'date' => 'date',
            'minutes' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CompOffRequest::class, 'comp_off_request_id');
    }
}
