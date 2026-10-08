<?php

namespace App\Services;

use App\Models\IssueType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueTypeService
{
    /**
     * @return Collection<int, IssueType>
     */
    public function list(): Collection
    {
        return IssueType::query()
            ->withCount('tasks')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): IssueType
    {
        $slug = Str::slug($data['slug'] ?? $data['name']);

        if (IssueType::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An issue type with this name or slug already exists.',
            ]);
        }

        $maxPosition = IssueType::max('position') ?? 0;

        return IssueType::create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? 'check-square',
            'color' => $data['color'] ?? '#3b82f6',
            'is_subtask' => $data['is_subtask'] ?? false,
            'position' => $data['position'] ?? ($maxPosition + 1),
        ]);
    }

    public function update(IssueType $issueType, array $data): IssueType
    {
        if (isset($data['name']) && $data['name'] !== $issueType->name) {
            $slug = Str::slug($data['slug'] ?? $data['name']);
            $existing = IssueType::where('slug', $slug)
                ->where('id', '!=', $issueType->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'name' => 'An issue type with this name or slug already exists.',
                ]);
            }

            $issueType->slug = $slug;
        }

        $issueType->update([
            'name' => $data['name'] ?? $issueType->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $issueType->description,
            'icon' => array_key_exists('icon', $data) ? $data['icon'] : $issueType->icon,
            'color' => array_key_exists('color', $data) ? $data['color'] : $issueType->color,
            'is_subtask' => array_key_exists('is_subtask', $data) ? $data['is_subtask'] : $issueType->is_subtask,
            'position' => array_key_exists('position', $data) ? $data['position'] : $issueType->position,
        ]);

        return $issueType;
    }

    public function delete(IssueType $issueType): void
    {
        if ($issueType->tasks()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Cannot delete an issue type currently assigned to tasks.',
            ]);
        }

        $issueType->delete();
    }
}
