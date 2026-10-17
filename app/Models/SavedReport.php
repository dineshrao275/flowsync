<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    protected $fillable = ['user_id', 'name', 'dataset', 'columns', 'filters', 'schedule', 'last_sent_at'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array', 'last_sent_at' => 'datetime'];
    }
}
