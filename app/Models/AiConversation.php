<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A user's chat with the assistant. Tenant-DB data, readable only by its owner. */
class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'product', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class, 'conversation_id');
    }
}
