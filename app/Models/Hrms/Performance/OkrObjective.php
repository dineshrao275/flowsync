<?php

namespace App\Models\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OkrObjective extends Model
{
    protected $table = 'okr_objectives';

    protected $fillable = ['employee_id', 'parent_id', 'title', 'period', 'start_date', 'end_date', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'objective_id')->orderBy('id');
    }

    /** Mean of the key results' progress, 0–100; an objective with none is 0. */
    public function progress(): int
    {
        $krs = $this->relationLoaded('keyResults') ? $this->keyResults : $this->keyResults()->get();

        return $krs->isEmpty() ? 0 : (int) round($krs->avg(fn (OkrKeyResult $k) => $k->progress()));
    }
}
