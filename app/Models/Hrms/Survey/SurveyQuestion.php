<?php

namespace App\Models\Hrms\Survey;

use App\Enums\Hrms\SurveyQuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Survey/HRMS — one question on a template.
 *
 * The type decides the answer column (`scale`/`nps`/`yes_no` read
 * `value_number`, `text` reads `value_text`, `multiple_choice` reads
 * `value_json`) and the aggregation the results engine computes. `min`
 * and `max` bound numeric answers where the template bothers to set
 * them — the service enforces, this just carries.
 */
class SurveyQuestion extends Model
{
    protected $table = 'survey_questions';

    protected $fillable = [
        'template_id',
        'text',
        'type',
        'options',
        'is_required',
        'min',
        'max',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'type' => SurveyQuestionType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'min' => 'decimal:2',
            'max' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'template_id');
    }
}
