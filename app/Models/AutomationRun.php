<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRun extends Model
{
    protected $fillable = ['rule_id', 'event_uuid', 'task_id', 'status', 'summary'];
}
