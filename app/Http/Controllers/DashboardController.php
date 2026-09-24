<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ScopesVisibleTasks;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $visible = $this->visibleTaskQuery($user)->withCount(['subtasks', 'comments', 'attachments']);

        $open = fn (Builder $query) => $query->whereNull('tasks.completed_at')->whereNull('tasks.archived_at');

        $myOpen = $open((clone $visible)->where('tasks.assignee_id', $user->id));
        $myOverdue = (clone $myOpen)->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', now()->toDateString());
        $myDueSoon = (clone $myOpen)
            ->whereNotNull('tasks.due_date')
            ->whereDate('tasks.due_date', '>=', now()->toDateString())
            ->whereDate('tasks.due_date', '<=', now()->addDays(7)->toDateString());
        $inProgress = (clone $visible)->where('tasks.assignee_id', $user->id)
            ->whereHas('status', fn (Builder $builder) => $builder->where('category', 'in_progress'));
        $recent = (clone $visible)->whereNull('tasks.archived_at');

        return response()->json([
            'counts' => [
                'my_open' => (clone $myOpen)->count(),
                'my_overdue' => (clone $myOverdue)->count(),
                'my_due_soon' => (clone $myDueSoon)->count(),
                'open' => (clone $visible)->whereNull('tasks.completed_at')->count(),
                'done' => (clone $visible)->whereNotNull('tasks.completed_at')->count(),
                'in_progress' => (clone $inProgress)->count(),
            ],
            'my_open' => (clone $myOpen)->orderBy('tasks.due_date')->orderBy('tasks.id')->limit(6)->get()->map(fn (Task $task) => $this->presentTask($task)),
            'my_overdue' => (clone $myOverdue)->orderBy('tasks.due_date')->orderBy('tasks.id')->limit(6)->get()->map(fn (Task $task) => $this->presentTask($task)),
            'my_due_soon' => (clone $myDueSoon)->orderBy('tasks.due_date')->orderBy('tasks.id')->limit(6)->get()->map(fn (Task $task) => $this->presentTask($task)),
            'in_progress' => (clone $inProgress)->orderBy('tasks.updated_at', 'desc')->orderBy('tasks.id', 'desc')->limit(6)->get()->map(fn (Task $task) => $this->presentTask($task)),
            'recent' => (clone $recent)->orderBy('tasks.updated_at', 'desc')->orderBy('tasks.id', 'desc')->limit(8)->get()->map(fn (Task $task) => $this->presentTask($task)),
        ]);
    }
}
