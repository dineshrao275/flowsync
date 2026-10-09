<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Task;
use App\Models\Tenant;
use App\Services\Events\DomainEvents;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Time-based automation triggers: emits `task.overdue` and `task.due_soon` events for open
 * tasks, but only in projects that have an active rule listening, and at most once per task
 * per window (7 days overdue / 2 days due soon) so a rule does not fire every hour.
 */
class AutomationTimeTriggers extends Command
{
    protected $signature = 'automation:time-triggers {--all : every serviceable tenant}';

    protected $description = 'Emit overdue / due-soon task events for projects with matching automation rules';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $emitted = 0;
        $tenants = Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get();

        foreach ($tenants as $tenant) {
            $dbm->using($tenant, function () use (&$emitted): void {
                if (! Schema::hasTable('automation_rules')) {
                    return;
                }
                $emitted += $this->emitFor('task.overdue', 7)->count() + $this->emitFor('task.due_soon', 2)->count();
            });
        }

        $this->info("Emitted {$emitted} time-based event(s) across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, DomainEvent> */
    private function emitFor(string $type, int $windowDays)
    {
        $projects = AutomationRule::where('trigger', $type)->where('is_active', true)->pluck('project_id')->unique();
        if ($projects->isEmpty()) {
            return collect();
        }

        $today = now()->startOfDay();
        $tasks = Task::whereIn('project_id', $projects)->whereNull('completed_at')->whereNotNull('due_date')
            ->when($type === 'task.overdue', fn ($q) => $q->whereDate('due_date', '<', $today))
            ->when($type === 'task.due_soon', fn ($q) => $q->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $today->copy()->addDay()))
            ->get();

        $recent = DomainEvent::where('type', $type)->where('occurred_at', '>=', now()->subDays($windowDays))->pluck('subject_id')->all();

        return $tasks->reject(fn (Task $t) => in_array($t->id, $recent, true))
            ->map(fn (Task $t) => app(DomainEvents::class)->record($type, Task::class, $t->id, ['key' => $t->key, 'due_date' => $t->due_date?->toDateString()]))
            ->values();
    }
}
