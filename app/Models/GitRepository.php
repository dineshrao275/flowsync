<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A repository an installation grants access to. Rows are never deleted: project links keep their history. */
class GitRepository extends Model
{
    use CentralConnection;

    protected $fillable = [
        'uuid', 'git_connection_id', 'provider', 'provider_repo_id', 'full_name', 'name', 'owner',
        'default_branch', 'private', 'archived', 'disabled', 'available', 'html_url', 'clone_url',
        'ssh_url', 'permissions', 'pushed_at', 'provider_updated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $repository): void {
            $repository->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'private' => 'boolean',
            'archived' => 'boolean',
            'disabled' => 'boolean',
            'available' => 'boolean',
            'permissions' => 'array',
            'pushed_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }
}
