<?php

namespace App\Models\Hrms\Survey;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Survey/HRMS — one answer to one question.
 *
 * Exactly one value column is populated per row, chosen by the question
 * type — a row with two populated columns is a writer bug, and readers
 * must never have to guess which one won.
 */
class SurveyAnswer extends Model
{
    protected $table = 'survey_answers';

    protected $fillable = [
        'response_id',
        'question_id',
        'value_text',
        'value_number',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'response_id' => 'integer',
            'question_id' => 'integer',
            'value_number' => 'decimal:2',
            'value_json' => 'array',
        ];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'response_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }
}
