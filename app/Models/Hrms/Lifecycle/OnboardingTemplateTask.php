<?php

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\TaskOwnerScope;
use App\Enums\Hrms\TemplateTaskCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — one reusable checklist item.
 *
 * `due_offset_days` counts from the joining date: zero is day one, negative
 * is before they start. `is_mandatory` is what makes a case uncompletable
 * while the item is open — and what makes waiving it need a reason plus the
 * manage permission, rather than a quiet click.
 *
 * @property int $id
 * @property int $template_id
 * @property string $title
 * @property string|null $description
 * @property TemplateTaskCategory $category
 * @property TaskOwnerScope $owner_scope
 * @property int $due_offset_days
 * @property bool $is_mandatory
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OnboardingTemplate $template
 */
class OnboardingTemplateTask extends Model
{
    protected $table = 'onboarding_template_tasks';

    protected $fillable = [
        'template_id',
        'title',
        'description',
        'category',
        'owner_scope',
        'due_offset_days',
        'is_mandatory',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'category' => TemplateTaskCategory::class,
            'owner_scope' => TaskOwnerScope::class,
            'due_offset_days' => 'integer',
            'is_mandatory' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'template_id');
    }
}
