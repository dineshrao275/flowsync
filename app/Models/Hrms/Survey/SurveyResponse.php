<?php

namespace App\Models\Hrms\Survey;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Survey/HRMS — one submission to one campaign.
 *
 * Identified rows link the employee (one per campaign, constrained);
 * anonymous rows link nobody and fingerprint on
 * `md5(campaign_id . ip_hash . user_agent)` instead. Nulls never collide
 * in either unique index, so the two shapes share one table without
 * tripping each other's constraint.
 */
class SurveyResponse extends Model
{
    protected $table = 'survey_responses';

    protected $fillable = [
        'campaign_id',
        'employee_id',
        'respondent_key',
        'started_at',
        'submitted_at',
        'ip_hash',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'employee_id' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SurveyCampaign::class, 'campaign_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<SurveyAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class, 'response_id');
    }
}
