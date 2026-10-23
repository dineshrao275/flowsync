<?php

declare(strict_types=1);

namespace App\Models\Hrms\Performance;

use Illuminate\Database\Eloquent\Model;

class OkrKeyResult extends Model
{
    protected $table = 'okr_key_results';

    protected $fillable = ['objective_id', 'title', 'start_value', 'target_value', 'current_value', 'unit'];

    protected function casts(): array
    {
        return ['start_value' => 'float', 'target_value' => 'float', 'current_value' => 'float'];
    }

    /** Percent of the way from start to target, clamped to 0–100 (works for targets below the start, e.g. "reduce churn"). */
    public function progress(): float
    {
        $span = $this->target_value - $this->start_value;

        return $span == 0.0 ? ($this->current_value >= $this->target_value ? 100.0 : 0.0)
            : max(0.0, min(100.0, ($this->current_value - $this->start_value) / $span * 100));
    }
}
