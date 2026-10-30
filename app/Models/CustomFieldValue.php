<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomFieldValue extends Model
{
    protected $fillable = ['definition_id', 'entity', 'entity_id', 'value', 'value_text', 'value_number', 'value_date'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }
}
