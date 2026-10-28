<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Models\User;
use App\Services\Reports\ReportRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Values a person would type that no validation rule reveals: a catalogue key, a record that must be in the right
 * state, a date window that avoids data the audit itself created. Each is forced into the payload for that route.
 * Everything here is realistic input for the real form, never a way around a business rule.
 */
final class Overrides
{
    /** @return array<string, mixed> */
    public static function payload(string $key, array $params): array
    {
        $task = fn (?int $projectId = null) => self::value('tasks', 'id', $projectId !== null ? ['project_id' => $projectId, 'parent_id' => null] : []);
        $projectId = isset($params['project']) ? (int) $params['project'] : null;
        $reportColumn = fn () => array_key_first((app(ReportRunner::class)->catalog()['tasks']['columns'] ?? ['title' => 'Title']));

        return match ($key) {
            'POST api/api-tokens' => ['name' => 'Audit token', 'abilities' => [array_key_first((array) config('api.abilities'))]],
            // Corrections are only accepted up to 7 days back.
            'POST api/hrms/attendance/regularizations' => ['work_date' => '2026-10-27', 'requested_first_in_at' => '2026-10-27 09:00:00'],
            'POST api/hrms/shifts', 'PUT api/hrms/shifts/{shift}' => [
                'start_time' => '09:00', 'end_time' => '18:00',
                'segments' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']],
            ],
            // A rotation slot is an active shift (or a day off).
            'POST api/hrms/shifts/rotations', 'PUT api/hrms/shifts/rotations/{rotation}' => ['cycle' => self::activeShifts(2)],
            'POST api/hrms/loans' => ['kind' => 'loan'],
            'POST api/hrms/shift-swaps' => ['swap_date' => '2026-11-23'],
            'POST api/hrms/leave/requests' => ['from_date' => '2026-12-07', 'to_date' => '2026-12-08'],
            'POST api/hrms/improvement-plans/{plan}/extend' => ['end_date' => '2027-12-31'],
            'POST api/report-builder', 'POST api/report-builder/preview', 'PUT api/report-builder/{report}' => ['dataset' => 'tasks', 'columns' => [$reportColumn()]],
            'POST api/webhooks', 'PUT api/webhooks/{webhook}' => ['url' => 'https://example.com/hooks', 'events' => ['task.created']],
            'POST api/hrms/performance/goals/{goal}/tasks' => ['task_id' => $task()],
            'POST api/projects/{project}/automations', 'PUT api/projects/{project}/automations/{rule}' => [
                'name' => 'Audit rule', 'trigger' => 'task.created', 'conditions' => [],
                'actions' => [['type' => 'add_comment', 'text' => 'Created by the form audit']],
            ],
            'POST api/projects/{project}/sprints/{sprint}/tasks' => ['task_ids' => array_filter([$task($projectId)])],
            'POST api/projects/{project}/tasks/{task}/dependencies' => [
                'depends_on_task_id' => self::value('tasks', 'id', ['project_id' => $projectId], (int) ($params['task'] ?? 0)),
                'type' => 'blocks',
            ],
            'POST api/saved-filters', 'POST api/system/saved-filters', 'POST api/projects/{project}/saved-filters' => ['page' => 'tasks', 'filters' => ['status' => 'open']],
            'POST api/workspaces/{workspace}/projects/from-template' => ['template_id' => self::value('project_templates', 'id')],
            'POST api/hrms/holidays/calendars/{calendar}/import', 'POST api/hrms/holidays/calendars/{calendar}/import/preview' => [
                'file' => UploadedFile::fake()->createWithContent('holidays.csv', "date,name\n2026-12-25,Audit Christmas\n2026-12-31,Audit Year End\n"),
            ],
            'PUT api/notification-preferences' => ['preferences' => [(config('notifications.events')[0] ?? 'task.assigned') => true]],
            'PUT api/notification-policies' => ['policies' => [(config('notifications.email_events')[0] ?? 'task.assigned') => true]],
            'PUT api/tenants/{tenant}/hrms' => ['enabled' => true],
            'PUT api/system/users/{user}/platform-roles' => ['roles' => ['auditor']],
            'POST api/hrms/leave/requests/{leaveRequest}/reject' => ['reason' => 'Audit leave rejection reason'],
            'POST api/tenants/{tenant}/subscription/split' => [
                'tms_plan_id' => \App\Models\SubscriptionPlan::where('product', 'tms')->orderBy('id')->value('id'),
                'hrms_plan_id' => \App\Models\SubscriptionPlan::where('product', 'hrms')->orderBy('id')->value('id'),
            ],
            'PUT api/security/two-factor-policy' => ['roles' => ['viewer']],
            // The status must belong to the task's own project.
            'POST api/projects/{project}/tasks/{task}/move' => ['status_id' => self::value('task_statuses', 'id', ['project_id' => $projectId]), 'index' => 0],
            // Move a task to a DIFFERENT project (the form picks one from a list; there is no free-text id).
            'POST api/projects/{project}/tasks/{task}/move-project' => ['target_project_id' => self::value('projects', 'id', [], $projectId ?? 0)],
            // Sign in as a real account: the form only succeeds with credentials that exist.
            'POST api/auth/login' => ['email' => User::query()->orderBy('id')->value('email'), 'password' => 'password'],
            default => [],
        };
    }

    /** @return list<int> ids of active shifts, repeated if there are fewer than $count */
    private static function activeShifts(int $count): array
    {
        $ids = Schema::hasTable('attendance_shifts') ? DB::table('attendance_shifts')->where('is_active', true)->orderBy('id')->pluck('id')->all() : [];
        $out = [];
        for ($i = 0; $i < $count && $ids !== []; $i++) {
            $out[] = (int) $ids[$i % count($ids)];
        }

        return $out;
    }

    /** Route values the model cannot supply: a catalogue key the route constrains to a fixed set. */
    public static function params(string $key, array $resolved = []): array
    {
        return match ($key) {
            'POST api/roles/templates/{key}' => ['key' => 'read_only'],
            'PUT api/hrms/approvals/chains/{domain}', 'POST api/hrms/approvals/chains/{domain}/reset' => ['domain' => (string) array_key_first((array) config('approvals.domains'))],
            'DELETE api/hrms/performance/goals/{goal}/tasks/{task}' => [
                'goal' => self::value('goal_task_links', 'goal_id'),
                'task' => self::value('goal_task_links', 'task_id'),
            ],
            // Member routes act on someone who IS a member of that project / workspace.
            'PUT api/projects/{project}/members/{user}', 'DELETE api/projects/{project}/members/{user}' => ['user' => self::value('project_members', 'user_id', ['project_id' => $resolved['project'] ?? 0], 0, 'desc')],
            'PUT api/workspaces/{workspace}/members/{user}', 'DELETE api/workspaces/{workspace}/members/{user}' => ['user' => self::value('workspace_members', 'user_id', ['workspace_id' => $resolved['workspace'] ?? 0], 0, 'desc')],
            // The action id lives inside the plan's own action list.
            'PUT api/hrms/development-plans/{plan}/actions/{action}' => ['action' => self::firstPlanAction($resolved['plan'] ?? null)],
            default => [],
        };
    }

    private static function firstPlanAction(mixed $planId): string
    {
        if ($planId === null || ! Schema::hasTable('development_plans')) {
            return '1';
        }
        $actions = json_decode((string) DB::table('development_plans')->where('id', $planId)->value('actions'), true);

        return (string) ($actions[0]['id'] ?? '1');
    }

    /** @return list<int> ids of active shifts

    /** First value of a column, optionally filtered, skipping one id. */
    private static function value(string $table, string $column, array $where = [], int $except = 0, string $order = 'asc'): mixed
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $query = DB::table($table);
        foreach ($where as $k => $v) {
            $v === null ? $query->whereNull($k) : $query->where($k, $v);
        }
        if ($except > 0) {
            $query->where($column, '!=', $except);
        }

        return $query->orderBy($column, $order)->value($column);
    }
}
