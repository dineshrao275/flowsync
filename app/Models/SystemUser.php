<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;

/**
 * Platform (super admin) account that lives in the central `system` database.
 *
 * Tenant users are the plain `App\Models\User` resolved against whichever
 * tenant database the request currently uses as its default; this model pins
 * itself to the system connection and re-allows the `is_super_admin` column
 * (removed from `User::$fillable`, which models tenant-database users).
 */
class SystemUser extends User
{
    use CentralConnection;

    /**
     * Platform super admins live in the central `users` table (no separate
     * `system_users` table exists); pin it explicitly — Eloquent would otherwise
     * infer `system_users` from this class name.
     */
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
    ];
}
