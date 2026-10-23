<?php

declare(strict_types=1);

namespace App\Models\Hrms\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordComment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'record_comments';

    protected $fillable = ['commentable_type', 'commentable_id', 'user_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
