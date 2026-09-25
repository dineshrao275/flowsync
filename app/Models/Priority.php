<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Priority extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'value',
        'color',
        'position',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'priority_id');
    }
}
