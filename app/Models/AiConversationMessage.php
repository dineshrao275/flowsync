<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = ['conversation_id', 'role', 'content', 'tool_name', 'payload', 'input_tokens', 'output_tokens'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
