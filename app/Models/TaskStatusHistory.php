<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'task_status_history';

    protected $fillable = ['task_id', 'from_status_id', 'to_status_id', 'user_id', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
