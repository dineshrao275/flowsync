<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ProjectVersionService
{
    /**
     * @return Collection<int, ProjectVersion>
     */
    public function list(Project $project): Collection
    {
        return $project->versions()
            ->withCount('tasks')
            ->orderBy('release_date', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function create(Project $project, array $data): ProjectVersion
    {
        $existing = $project->versions()->where('name', $data['name'])->exists();
        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'A version with this name already exists in this project.',
            ]);
        }

        return $project->versions()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'release_date' => $data['release_date'] ?? null,
            'released' => $data['released'] ?? false,
            'archived' => $data['archived'] ?? false,
        ]);
    }

    public function update(ProjectVersion $version, array $data): ProjectVersion
    {
        if (isset($data['name']) && $data['name'] !== $version->name) {
            $existing = $version->project->versions()
                ->where('name', $data['name'])
                ->where('id', '!=', $version->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'name' => 'A version with this name already exists in this project.',
                ]);
            }
        }

        $version->update([
            'name' => $data['name'] ?? $version->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $version->description,
            'release_date' => array_key_exists('release_date', $data) ? $data['release_date'] : $version->release_date,
            'released' => array_key_exists('released', $data) ? $data['released'] : $version->released,
            'archived' => array_key_exists('archived', $data) ? $data['archived'] : $version->archived,
        ]);

        return $version;
    }

    public function delete(ProjectVersion $version): void
    {
        $version->delete();
    }
}
