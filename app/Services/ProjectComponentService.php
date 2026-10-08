<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ProjectComponentService
{
    /**
     * @return Collection<int, ProjectComponent>
     */
    public function list(Project $project): Collection
    {
        return $project->components()
            ->with(['lead'])
            ->withCount('tasks')
            ->orderBy('name')
            ->get();
    }

    public function create(Project $project, array $data): ProjectComponent
    {
        $existing = $project->components()->where('name', $data['name'])->exists();
        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'A component with this name already exists in this project.',
            ]);
        }

        return $project->components()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'lead_user_id' => $data['lead_user_id'] ?? null,
        ]);
    }

    public function update(ProjectComponent $component, array $data): ProjectComponent
    {
        if (isset($data['name']) && $data['name'] !== $component->name) {
            $existing = $component->project->components()
                ->where('name', $data['name'])
                ->where('id', '!=', $component->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'name' => 'A component with this name already exists in this project.',
                ]);
            }
        }

        $component->update([
            'name' => $data['name'] ?? $component->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $component->description,
            'lead_user_id' => array_key_exists('lead_user_id', $data) ? $data['lead_user_id'] : $component->lead_user_id,
        ]);

        return $component;
    }

    public function delete(ProjectComponent $component): void
    {
        $component->delete();
    }
}
