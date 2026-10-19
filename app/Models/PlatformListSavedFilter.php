<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved filter set for a super-admin listing page (system DB). */
class PlatformListSavedFilter extends Model
{
    use CentralConnection;

    protected $table = 'list_saved_filters';

    protected $fillable = ['owner_user_id', 'page', 'name', 'filters', 'is_shared'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'is_shared' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'owner_user_id');
    }
}
