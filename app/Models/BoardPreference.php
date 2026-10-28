<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user's personal view of one project's kanban board (hidden/collapsed
 * columns, column width, card density). Never shared: the shared column
 * definition is `task_statuses`.
 */
class BoardPreference extends Model
{
    public const WIDTHS = ['compact', 'default', 'wide'];

    public const DENSITIES = ['compact', 'comfortable'];

    protected $fillable = ['user_id', 'project_id', 'preferences'];

    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'hidden' => [],
            'collapsed' => [],
            'width' => 'default',
            'density' => 'comfortable',
            'hide_empty' => false,
        ];
    }
}
