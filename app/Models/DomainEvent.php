<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A business fact recorded by the domain event bus. Tenant DB, append-only. */
class DomainEvent extends Model
{
    protected $fillable = ['uuid', 'type', 'subject_type', 'subject_id', 'actor_user_id', 'data', 'occurred_at', 'processed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
