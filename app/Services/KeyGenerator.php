<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class KeyGenerator
{
    /**
     * Atomically allocate the next task key (e.g. "WEB-42") for a project.
     *
     * Uses a transaction and an explicit sequence bump so the returned key is
     * guaranteed unique, guarded by the unique(project_id, sequence) constraint.
     *
     * Returns [key, sequence].
     *
     * @return array{0: string, 1: int}
     */
    public function nextTaskKey(Project $project): array
    {
        return DB::transaction(function () use ($project) {
            $locked = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            $locked->increment('last_task_sequence');

            return [
                $this->format($project->key, $locked->last_task_sequence),
                $locked->last_task_sequence,
            ];
        });
    }

    public function format(string $projectKey, int $sequence): string
    {
        return strtoupper($projectKey).'-'.$sequence;
    }
}
