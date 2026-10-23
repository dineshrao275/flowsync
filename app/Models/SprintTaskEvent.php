<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SprintTaskEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['sprint_id', 'task_id', 'type', 'points', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'points' => 'float'];
    }
}
