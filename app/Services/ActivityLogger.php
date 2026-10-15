<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use App\Services\Events\DomainEvents;
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
        $activity = Activity::create([
            'actor_user_id' => $actor?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'data' => $data,
            'ip_address' => $ipAddress,
        ]);

        // Every activity is also a domain event: one funnel feeds the timeline and
        // the integrations. (A task moved into a done status is additionally `task.completed`.)
        $events = app(DomainEvents::class);
        $events->record($action, $subjectType, $subjectId, $data ?? [], $actor?->id);
        if (in_array($action, ['task.moved', 'task.updated'], true) && ! empty($data['to_is_done'])) {
            $events->record('task.completed', $subjectType, $subjectId, $data, $actor?->id);
        }

        return $activity;
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
