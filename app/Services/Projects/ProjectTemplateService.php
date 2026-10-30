<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\TaskStatusCategory;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectTemplate;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a project's setup as a template and start new projects from one.
 *
 * What travels: the workflow (statuses with category/colour/WIP limit/entry rules, the allowed
 * transitions and whether they are enforced), components, and — if asked — the open top-level tasks
 * as starter work (title, description, status). What does NOT: members, history, comments, dates,
 * assignees, labels (they belong to a workspace) — a template is a blueprint, not a copy.
 */
class ProjectTemplateService
{
    private const MAX_STARTER_TASKS = 100;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly TaskService $tasks,
    ) {}

    public function fromProject(Project $project, string $name, ?string $description, bool $includeTasks, User $by): ProjectTemplate
    {
        $statuses = $project->statuses()->orderBy('position')->get();
        $slugById = $statuses->pluck('slug', 'id');

        $snapshot = [
            'enforce_workflow' => (bool) $project->enforce_workflow,
            'statuses' => $statuses->map(fn (TaskStatus $s) => [
                'name' => $s->name, 'slug' => $s->slug, 'category' => $s->category->value, 'color' => $s->color,
                'is_default' => (bool) $s->is_default, 'is_done' => (bool) $s->is_done,
                'wip_limit' => $s->wip_limit, 'entry_rules' => $s->entry_rules,
            ])->values()->all(),
            'transitions' => StatusTransition::query()->where('project_id', $project->id)->get()->map(fn ($t) => [
                'from' => $t->from_status_id ? ($slugById[$t->from_status_id] ?? null) : null,
                'to' => $slugById[$t->to_status_id] ?? null,
            ])->filter(fn ($t) => $t['to'] !== null)->values()->all(),
            'components' => ProjectComponent::query()->where('project_id', $project->id)->get()
                ->map(fn ($c) => ['name' => $c->name, 'description' => $c->description])->values()->all(),
            'tasks' => $includeTasks
                ? Task::query()->where('project_id', $project->id)->whereNull('parent_id')->whereNull('completed_at')
                    ->orderBy('id')->limit(self::MAX_STARTER_TASKS)->get()
                    ->map(fn (Task $t) => ['title' => $t->title, 'description' => $t->description, 'status' => $slugById[$t->status_id] ?? null])->values()->all()
                : [],
        ];

        return ProjectTemplate::create([
            'name' => $name,
            'description' => $description,
            'created_by' => $by->id,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  name, key?, description?, icon?, color? as for a plain project
     */
    public function instantiate(Workspace $workspace, ProjectTemplate $template, array $data, User $by): Project
    {
        $snapshot = $template->snapshot;

        if (empty($snapshot['statuses'])) {
            throw ValidationException::withMessages(['template_id' => __('This template has no workflow to copy.')]);
        }

        return DB::transaction(function () use ($workspace, $snapshot, $data, $by): Project {
            $project = $this->projects->create($workspace, $data, $by);

            // Swap the default workflow for the template's. The project is brand new, so no task can use a status.
            StatusTransition::query()->where('project_id', $project->id)->delete();
            $project->statuses()->delete();

            $idBySlug = [];
            foreach ($snapshot['statuses'] as $i => $s) {
                $status = TaskStatus::create([
                    'project_id' => $project->id,
                    'name' => $s['name'],
                    'slug' => $s['slug'],
                    'category' => TaskStatusCategory::from($s['category']),
                    'position' => $i + 1,
                    'color' => $s['color'] ?? null,
                    'is_default' => (bool) ($s['is_default'] ?? false),
                    'is_done' => (bool) ($s['is_done'] ?? false),
                    'wip_limit' => $s['wip_limit'] ?? null,
                    'entry_rules' => $s['entry_rules'] ?? null,
                ]);
                $idBySlug[$s['slug']] = $status->id;
            }

            foreach ($snapshot['transitions'] ?? [] as $t) {
                if (isset($idBySlug[$t['to']]) && ($t['from'] === null || isset($idBySlug[$t['from']]))) {
                    StatusTransition::create([
                        'project_id' => $project->id,
                        'from_status_id' => $t['from'] === null ? null : $idBySlug[$t['from']],
                        'to_status_id' => $idBySlug[$t['to']],
                    ]);
                }
            }

            $project->update(['enforce_workflow' => (bool) ($snapshot['enforce_workflow'] ?? false)]);

            foreach ($snapshot['components'] ?? [] as $c) {
                ProjectComponent::create(['project_id' => $project->id, 'name' => $c['name'], 'description' => $c['description'] ?? null]);
            }

            foreach ($snapshot['tasks'] ?? [] as $task) {
                $this->tasks->create($project, array_filter([
                    'title' => $task['title'],
                    'description' => $task['description'] ?? null,
                    'status_id' => $idBySlug[$task['status'] ?? ''] ?? null,
                ], fn ($v) => $v !== null), $by);
            }

            return $project->refresh();
        });
    }
}
