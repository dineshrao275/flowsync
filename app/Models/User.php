<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Hrms\Employee\Employee;
use App\Support\PermissionCatalog;
use App\Support\PermissionScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_default',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The tenant's protected default user can never be deleted — not by the
     * API, and not by any other code path that deletes a User model.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            if ($user->is_default) {
                throw ValidationException::withMessages([
                    'form' => 'The default user cannot be deleted. Shift the default to another admin first.',
                ]);
            }
        });
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * The employment record for this login, if there is one.
     *
     * The inverse of `employees.user_id`, which is a **unique nullable** FK:
     * most people have exactly one, and a service account (an integration, a
     * super admin, a login nobody pays a salary to) has none at all. The
     * inverse has to exist for the backfill's "every user without an employee"
     * query, and for the repair case where a login and its record disagree.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public static function defaultUser(): ?self
    {
        return static::query()->default()->orderBy('id')->first();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function watchedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_watchers', 'user_id', 'task_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function hasPermission(string $slug): bool
    {
        // The admin role is `*` by definition. Its stored snapshot lags every
        // permission added after the tenant was provisioned, and a lagging
        // snapshot must never hide a feature the tenant's plan includes — what
        // the plan does not include is switched off by the module gates instead.
        if (PermissionCatalog::has($slug) && $this->hasRole('admin')) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->permissions->contains('slug', $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scope-aware permission check.
     *
     * `hasPermission('hrms.leave.view_all')` only answers for a literal
     * grant of that slug; this asks the wider question — does any grant the
     * user holds SATISFY the check — via App\Support\PermissionScope (a wider
     * scope, or the legacy unsuffixed slug that has always meant "all").
     *
     * Non-scoped slugs resolve to an exact match, so this is a drop-in
     * replacement for hasPermission() everywhere and does not change any
     * behaviour until a caller is handed a `_own`/`_assigned`/`_all` slug.
     */
    public function granted(string $slug): bool
    {
        foreach (PermissionScope::satisfying($slug) as $candidate) {
            if ($this->hasPermission($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function permissionSlugs(): array
    {
        if ($this->hasRole('admin')) {
            return PermissionCatalog::slugs(); // see hasPermission()
        }

        $slugs = [];

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $slugs[] = $permission->slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->values()->all();
    }
}
