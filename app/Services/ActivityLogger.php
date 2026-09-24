<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogger
{
    public function log(
        string $subjectType,
        int $subjectId,
        string $action,
        ?array $data = null,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): Activity {
        return Activity::create([
            'actor_user_id' => $actor?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'data' => $data,
            'ip_address' => $ipAddress,
        ]);
    }

    public function forSubject(string $subjectType, int $subjectId): Builder
    {
        return Activity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with('actor')
            ->orderByDesc('id');
    }
}
