<?php

namespace App\Models\Hrms\Survey;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Survey/HRMS — one question's computed aggregates for one campaign.
 *
 * A snapshot, not a view: computed on read, cached warm, and kept after
 * close so history survives answer deletions. Below the anonymity
 * threshold no snapshot is written at all — an empty result with no row
 * behind it, because a row saying "three people answered" next to a
 * locked campaign is itself a disclosure.
 */
class SurveyResult extends Model
{
    protected $table = 'survey_results';

    protected $fillable = [
        'campaign_id',
        'question_id',
        'aggregates',
        'response_count',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'question_id' => 'integer',
            'aggregates' => 'array',
            'response_count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SurveyCampaign::class, 'campaign_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }
}
