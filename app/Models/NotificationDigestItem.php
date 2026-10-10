<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One emailable notification held back for a user's next digest (digest mode, or throttle overflow). */
class NotificationDigestItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'type', 'actor_name', 'data', 'created_at', 'sent_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
