<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Support\Str;

/**
 * Turns the provisioned `owner@{slug}.test` admin into the tenant's real default
 * user. Shared by self-registration and the Super Admin intake so both end with
 * the same account: the oldest admin, which `ensureDefaultUser()` marks default.
 */
class DefaultUserClaim
{
    public function __construct(
        private readonly TenantDatabaseManager $dbm,
        private readonly TenantProvisioner $provisioner,
        private readonly EmployeeBackfill $backfill,
    ) {}

    /** @param string $passwordHash an already-hashed password (never plaintext in a queue payload) */
    public function claim(Tenant $tenant, string $name, string $email, string $passwordHash): User
    {
        $email = Str::lower($email);
        $placeholder = "owner@{$tenant->slug}.test";

        $user = $this->dbm->using($tenant, function () use ($placeholder, $name, $email, $passwordHash): User {
            $user = User::where('email', $placeholder)->first()
                ?? (new User)->forceFill(['email' => $placeholder]);

            $user->name = $name;
            $user->email = $email;
            $user->password = $passwordHash; // 'hashed' cast keeps an existing hash as-is
            $user->save();

            return $user;
        });

        // The email was swapped, so drop the placeholder's routing row and
        // re-mirror — the index must never point at a ghost login.
        TenantUserRouting::where('tenant_id', $tenant->id)->where('email', $placeholder)->delete();
        $this->provisioner->syncRouting($this->dbm, $tenant);

        // Link the employment record now so self-service works on first sign-in.
        $this->dbm->using($tenant, fn () => $this->backfill->linkFor($user));

        return $user;
    }
}
