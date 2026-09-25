<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
        foreach ($this->roles as $role) {
            if ($role->permissions->contains('slug', $slug)) {
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
