<?php

namespace App\Models\Hrms\Lifecycle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — a reusable onboarding checklist.
 *
 * The catalogue, not the run: a case materialises its own task copies at
 * creation, so editing this template changes future hires and never rewrites
 * someone’s in-flight onboarding. `is_system` marks the rows the seeder (a
 * later task) installs, which a tenant may rename but not delete while cases
 * point at them.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OnboardingTemplateTask> $tasks
 */
class OnboardingTemplate extends Model
{
    protected $table = 'onboarding_templates';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTemplateTask::class, 'template_id')->orderBy('position')->orderBy('id');
    }

    /** @param Builder<OnboardingTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
