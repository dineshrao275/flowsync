<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedReport extends Model
{
    protected $fillable = ['user_id', 'name', 'dataset', 'columns', 'filters', 'schedule', 'last_sent_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array', 'last_sent_at' => 'datetime'];
    }
}
