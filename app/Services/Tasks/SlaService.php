<?php

namespace App\Services\Tasks;

use App\Models\SlaPolicy;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Task SLAs. A policy says "tasks in this project (optionally of this priority) are resolved within N
 * hours of being created". The sweep finds open tasks past that point, records one breach per
 * (policy, task) and emits `task.sla_breached` on the activity/event funnel, so webhooks and HR/TMS
 * automation can react, and tells the assignee.
 */
class SlaService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
    ) {}

    /** @return int how many new breaches were recorded */
    public function sweep(): int
    {
        $count = 0;

        SlaPolicy::query()->where('is_active', true)->get()->each(function (SlaPolicy $policy) use (&$count): void {
            $tasks = Task::query()
                ->where('project_id', $policy->project_id)
                ->whereNull('completed_at')
                ->when($policy->priority_id, fn ($q) => $q->where('priority_id', $policy->priority_id))
                ->where('created_at', '<=', now()->subHours($policy->target_hours))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sla_breaches')
                    ->whereColumn('sla_breaches.task_id', 'tasks.id')->where('sla_breaches.sla_policy_id', $policy->id))
                ->with(['project', 'assignee'])
                ->limit(500)
                ->get();

            foreach ($tasks as $task) {
                DB::table('sla_breaches')->insert(['sla_policy_id' => $policy->id, 'task_id' => $task->id, 'breached_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                $data = ['key' => $task->key, 'title' => $task->title, 'sla_policy_id' => $policy->id, 'sla' => $policy->name, 'target_hours' => $policy->target_hours];
                $this->activity->log(Task::class, $task->id, 'task.sla_breached', $data);

                if ($task->assignee !== null) {
                    $this->notifications->notify($task->assignee, 'task.sla_breached', $data + [
                        'task_id' => $task->id, 'project_id' => $task->project_id,
                        'project_name' => $task->project?->name, 'workspace_id' => $task->project?->workspace_id,
                    ]);
                }
                $count++;
            }
        });

        return $count;
    }
}
