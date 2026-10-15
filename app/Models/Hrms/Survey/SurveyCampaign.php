<?php

namespace App\Models\Hrms\Survey;

use App\Enums\Hrms\SurveyCampaignStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Survey/HRMS — one template opened to one audience for one window.
 *
 * The state machine is scheduled → open → closed, forward only. The
 * anonymity threshold is copied from the template default at schedule
 * time but lives on the row — a campaign's promise must not move because
 * someone later edited the template.
 */
class SurveyCampaign extends Model
{
    protected $table = 'survey_campaigns';

    protected $fillable = [
        'template_id',
        'name',
        'starts_at',
        'ends_at',
        'status',
        'anonymity_threshold',
        'notify_on_publish',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => SurveyCampaignStatus::class,
            'anonymity_threshold' => 'integer',
            'notify_on_publish' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'template_id');
    }

    /** @return HasMany<SurveyResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'campaign_id');
    }

    /** @return HasMany<SurveyResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(SurveyResult::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
