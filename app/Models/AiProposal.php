<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reviewable set of changes the assistant suggested. Nothing in `payload` has happened yet;
 * `result` records, per item, what confirmation actually did.
 */
class AiProposal extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    protected $fillable = ['conversation_id', 'user_id', 'kind', 'project_id', 'document_id', 'payload', 'result', 'status', 'confirmed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'confirmed_at' => 'datetime'];
    }
}
