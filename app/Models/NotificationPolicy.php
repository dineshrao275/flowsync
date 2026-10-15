<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-wide switch for one event on one channel. Only `email` is switchable: in-app rows are
 * the record of what happened and are always written. No row means "allowed".
 */
class NotificationPolicy extends Model
{
    public const CHANNEL_EMAIL = 'email';

    protected $fillable = ['event', 'channel', 'enabled', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function allows(string $event, string $channel = self::CHANNEL_EMAIL): bool
    {
        $row = static::where('event', $event)->where('channel', $channel)->first();

        return $row === null || $row->enabled;
    }
}
