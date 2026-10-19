<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrAutomationRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['rule_id', 'event_uuid', 'employee_id', 'status', 'summary'];
}
