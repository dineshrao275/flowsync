<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One TOTP credential per account (P8.1). Deliberately on the DEFAULT
 * connection: a tenant user's row lives in their tenant DB, a platform
 * account's in the system DB - whichever database owns `users` owns this too.
 */
class TwoFactorCredential extends Model
{
    protected $fillable = ['user_id', 'secret', 'confirmed_at', 'recovery_codes', 'last_used_step'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'last_used_step' => 'integer',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
