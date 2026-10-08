<?php

namespace App\Models\Hrms\Survey;

use App\Enums\Hrms\SurveyAudienceScope;
use App\Enums\Hrms\SurveyFrequency;
use App\Enums\Hrms\SurveyType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Survey/HRMS — one reusable questionnaire.
 *
 * Questions hang off the template in sequence order; campaigns open the
 * template to an audience. `slug` is server-allocated and unique.
 */
class SurveyTemplate extends Model
{
    protected $table = 'survey_templates';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'is_anonymous',
        'is_active',
        'frequency',
        'audience_scope',
        'audience_meta',
        'settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => SurveyType::class,
            'is_anonymous' => 'boolean',
            'is_active' => 'boolean',
            'frequency' => SurveyFrequency::class,
            'audience_scope' => SurveyAudienceScope::class,
            'audience_meta' => 'array',
            'settings' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /** @return HasMany<SurveyQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class, 'template_id')->orderBy('sequence');
    }

    /** @return HasMany<SurveyCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(SurveyCampaign::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
