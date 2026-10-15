<?php

namespace App\Models\Hrms\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared/HRMS — the editable approval chain for one domain (P2.4).
 *
 * `steps` is the ordered list of step definitions (see `config/approvals.php`
 * for the shape). A domain without a row falls back to the config default, so
 * behaviour never depends on whether the seed ran.
 *
 * @property int $id
 * @property string $domain
 * @property string $name
 * @property list<array<string, mixed>> $steps
 * @property int|null $sla_hours
 * @property int|null $reminder_before_hours
 * @property string|null $escalation_role_slug
 * @property bool $is_active
 * @property int|null $updated_by_user_id
 */
class ApprovalTemplate extends Model
{
    protected $table = 'approval_templates';

    protected $fillable = [
        'domain',
        'name',
        'steps',
        'sla_hours',
        'reminder_before_hours',
        'escalation_role_slug',
        'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'sla_hours' => 'integer',
            'reminder_before_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
