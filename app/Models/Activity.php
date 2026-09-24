<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'subject_type',
        'subject_id',
        'action',
        'data',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
