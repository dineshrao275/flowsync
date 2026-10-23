<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectDashboard extends Model
{
    protected $fillable = ['project_id', 'user_id', 'widgets'];

    protected function casts(): array
    {
        return ['widgets' => 'array'];
    }
}
