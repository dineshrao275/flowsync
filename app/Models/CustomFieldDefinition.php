<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tenant-defined field on one entity type (see CustomFieldService for the type catalogue). */
class CustomFieldDefinition extends Model
{
    protected $fillable = [
        'entity', 'key', 'label', 'type', 'options', 'required',
        'is_filterable', 'is_sensitive', 'is_active', 'position', 'help_text',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_sensitive' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'definition_id');
    }
}
