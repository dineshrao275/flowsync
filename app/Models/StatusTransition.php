<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusTransition extends Model
{
    protected $fillable = ['project_id', 'from_status_id', 'to_status_id'];
}
