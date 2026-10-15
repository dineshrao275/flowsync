<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/** One step the dunning sweep took for a past-due subscription (system DB). */
class DunningAttempt extends Model
{
    use CentralConnection;

    protected $fillable = ['tenant_id', 'subscription_id', 'cycle', 'step_day', 'stage', 'outcome', 'data', 'executed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'executed_at' => 'datetime', 'step_day' => 'integer'];
    }
}
