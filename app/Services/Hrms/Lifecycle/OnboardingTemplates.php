<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\TaskOwnerScope;
use App\Enums\Hrms\TemplateTaskCategory;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\Hrms\Lifecycle\OnboardingTemplateTask;
use App\Models\User;
use App\Services\Hrms\OnboardingService;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — the reusable onboarding checklists.
 *
 * Split from {@see OnboardingService} because the
 * catalogue and the run are different lifecycles sharing only a name:
 * editing a template changes future hires, and must never rewrite
 * someone’s in-flight onboarding. Every mutation here touches template
 * rows only; cases keep the copies they materialised.
 */
class OnboardingTemplates
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Every template, with its items, ordered for the picker.
     *
     * @return Collection<int, OnboardingTemplate>
     */
    public function all(bool $activeOnly = true): Collection
    {
        $query = OnboardingTemplate::query()->with('tasks')->orderBy('name');

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * Create a template, optionally with its items in one call.
     *
     * @param  array{description?: string|null, is_active?: bool, tasks?: list<array<string, mixed>>}  $attributes
     *
     * @throws ValidationException on a missing name or a bad nested task
     */
    public function create(string $name, array $attributes = [], ?User $actor = null): OnboardingTemplate
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A template needs a short name.']);
        }

        return DB::transaction(function () use ($name, $attributes, $actor): OnboardingTemplate {
            $template = OnboardingTemplate::create([
                'name' => $name,
                'slug' => $this->naming->uniqueSlug(OnboardingTemplate::class, $name),
                'description' => $attributes['description'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);

            foreach (array_values($attributes['tasks'] ?? []) as $position => $task) {
                $this->insertTask($template, $task, ($position + 1) * 10);
            }

            $this->audit->log($template, 'onboarding.template_created', null, $this->snapshot($template->refresh()), $actor);

            return $template->refresh();
        });
    }

    /**
     * Rename, describe, or retire a template. Items have their own endpoints:
     * folding item edits into this one would let a rename silently rewrite
     * the checklist it only meant to retitle.
     *
     * @param  array{description?: string|null, is_active?: bool}  $attributes
     */
    public function update(OnboardingTemplate $template, string $name, array $attributes = [], ?User $actor = null): OnboardingTemplate
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A template needs a short name.']);
        }

        $before = $this->snapshot($template);

        return DB::transaction(function () use ($template, $name, $attributes, $actor, $before): OnboardingTemplate {
            $template->update([
                'name' => $name,
                'slug' => $name !== $template->name ? $this->naming->uniqueSlug(OnboardingTemplate::class, $name, $template->id) : $template->slug,
                'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $template->description,
                'is_active' => (bool) ($attributes['is_active'] ?? $template->is_active),
            ]);
            $this->audit->log($template, 'onboarding.template_updated', $before, $this->snapshot($template->refresh()), $actor);

            return $template->refresh();
        });
    }

    /**
     * Delete a template nobody is running from.
     *
     * Refused while any case references it — deleting would null the link on
     * running cases, and a case whose template vanished mid-run reads as
     * abandoned rather than deliberate. Retire (`is_active = false`) instead.
     *
     * @throws ValidationException while cases reference the template
     */
    public function delete(OnboardingTemplate $template, ?User $actor = null): void
    {
        if (OnboardingCase::where('template_id', $template->id)->exists()) {
            throw ValidationException::withMessages(['form' => 'Cases are still running from this template. Retire it instead of deleting it.']);
        }

        $snapshot = $this->snapshot($template);
        $template->delete();
        $this->audit->log($template, 'onboarding.template_deleted', null, $snapshot, $actor);
    }

    /**
     * Add one item to a template.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on a bad category, scope, or missing title
     */
    public function addTask(OnboardingTemplate $template, array $attributes, ?User $actor = null): OnboardingTemplateTask
    {
        return DB::transaction(function () use ($template, $attributes, $actor): OnboardingTemplateTask {
            $task = $this->insertTask($template, $attributes, $this->nextPosition($template));
            $this->audit->log($template, 'onboarding.template_task_added', null, $this->snapshot($template->refresh()), $actor);

            return $task->refresh();
        });
    }

    /**
     * Edit one template item. Cases already materialised keep their copies —
     * this changes future hires, never someone’s in-flight onboarding.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateTask(OnboardingTemplateTask $task, array $attributes, ?User $actor = null): OnboardingTemplateTask
    {
        return DB::transaction(function () use ($task, $attributes, $actor): OnboardingTemplateTask {
            $task->update($this->taskAttributes($attributes, true));
            $this->audit->log($task->template, 'onboarding.template_task_updated', null, $this->snapshot($task->template->refresh()), $actor);

            return $task->refresh();
        });
    }

    /**
     * Remove one template item.
     *
     * Refused while an *open* case still points at it: the running item would
     * lose its mandatory flag’s source, and a deleted catalogue row must not
     * quietly unblock a case (see CaseProgress::isMandatory). Closed cases
     * don’t matter — nobody completes those again.
     *
     * @throws ValidationException while open cases reference the item
     */
    public function removeTask(OnboardingTemplateTask $task, ?User $actor = null): void
    {
        $referenced = OnboardingCaseTask::where('template_task_id', $task->id)
            ->whereHas('case', fn ($query) => $query->open())
            ->exists();

        if ($referenced) {
            throw ValidationException::withMessages(['form' => 'Open cases are still running this item. Finish or cancel those cases first.']);
        }

        $template = $task->template;
        $task->delete();
        $this->audit->log($template, 'onboarding.template_task_removed', null, $this->snapshot($template->refresh()), $actor);
    }

    /**
     * Reorder a template’s items. Takes the whole id list: the client and the
     * server cannot disagree about what “position 2” means, and omitted
     * siblings keep their relative order at the end (see OrgNaming::order).
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderTasks(OnboardingTemplate $template, array $orderedIds): void
    {
        $siblings = $template->tasks()->pluck('id')->all();
        $ordered = $this->naming->order($orderedIds, $siblings);
        $positions = array_flip($ordered);
        $position = 0;

        $template->tasks()->whereIn('id', $ordered)->get()
            ->sortBy(fn (OnboardingTemplateTask $task): int => $positions[$task->id])
            ->values()
            ->each(function (OnboardingTemplateTask $task) use (&$position): void {
                // Renumber gap-free, mirroring OrgNaming::renumber: every
                // reorder collapses back to 1..N so gaps never accumulate. A
                // local counter, not a static: a static would keep counting
                // across reorder calls and the second drag-and-drop would
                // start where the first one left off.
                $task->position = ++$position;
                $task->save();
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function taskAttributes(array $attributes, bool $partial): array
    {
        $title = array_key_exists('title', $attributes) ? trim((string) $attributes['title']) : null;

        if (! $partial || $title !== null) {
            if ($title === '' || $title === null || mb_strlen($title) > 255) {
                throw ValidationException::withMessages(['title' => 'An item needs a short title.']);
            }
        }

        $resolved = [];

        if ($title !== null) {
            $resolved['title'] = $title;
        }

        foreach (['description', 'due_offset_days', 'is_mandatory'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $resolved[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('category', $attributes)) {
            $resolved['category'] = TemplateTaskCategory::tryFrom((string) $attributes['category'])
                ?? throw ValidationException::withMessages(['category' => 'That is not a checklist category.']);
        }

        if (array_key_exists('owner_scope', $attributes)) {
            $resolved['owner_scope'] = TaskOwnerScope::tryFrom((string) $attributes['owner_scope'])
                ?? throw ValidationException::withMessages(['owner_scope' => 'That scope does not name a real owner.']);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertTask(OnboardingTemplate $template, array $attributes, int $position): OnboardingTemplateTask
    {
        $resolved = $this->taskAttributes($attributes, false);

        return OnboardingTemplateTask::create([
            ...$resolved,
            'template_id' => $template->id,
            'due_offset_days' => (int) ($resolved['due_offset_days'] ?? 0),
            'is_mandatory' => (bool) ($resolved['is_mandatory'] ?? false),
            'position' => $position,
        ]);
    }

    private function nextPosition(OnboardingTemplate $template): int
    {
        return ((int) $template->tasks()->max('position')) + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OnboardingTemplate $template): array
    {
        return [
            'name' => $template->name,
            'slug' => $template->slug,
            'is_active' => $template->is_active,
        ];
    }
}
