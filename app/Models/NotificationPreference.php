<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'preferences',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the recipient wants this event delivered. Defaults to yes for every
     * catalogued event when no row — or no key on the row — exists: opt-in by
     * default, only an explicit `false` turns delivery off. Un-catalogued events
     * (HRMS nudges etc.) cannot be toggled and always deliver.
     */
    public static function wants(User $user, string $type): bool
    {
        if (! in_array($type, config('notifications.events', []), true)) {
            return true;
        }

        $row = static::firstWhere('user_id', $user->id);

        if ($row === null) {
            return true;
        }

        // Read the event key literally: `data_get` treats the dot in
        // "task.assigned" as a nesting separator and returns the default.
        $preferences = $row->preferences ?? [];

        return ($preferences[$type] ?? true) !== false;
    }
}
