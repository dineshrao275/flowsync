<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(
        TenantDatabaseManager $dbm,
        TenantProvisioner $provisioner,
        TenantLifecycle $lifecycle,
    ): void {
        $dbm->connectSystem();

        SystemUser::firstOrCreate(
            ['email' => 'superadmin@flowsync.test'],
            ['name' => 'Super Admin', 'password' => 'password', 'is_super_admin' => true]
        );

        $acme = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme Corp', 'description' => 'Primary demo tenant']
        );

        $provisioner->provisionIsolated($acme, $dbm, $lifecycle);

        $dbm->using($acme, function () use ($acme): void {
            foreach ([
                ['email' => 'admin@flowsync.test', 'name' => 'Admin User', 'role' => 'admin'],
                ['email' => 'editor@flowsync.test', 'name' => 'Editor User', 'role' => 'editor'],
                ['email' => 'viewer@flowsync.test', 'name' => 'Viewer User', 'role' => 'viewer'],
            ] as $demo) {
                $user = User::firstOrCreate(
                    ['email' => $demo['email']],
                    ['name' => $demo['name'], 'password' => 'password']
                );

                $role = Role::where('slug', $demo['role'])->first();
                if ($role) {
                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            }
        });

        // Mirror the demo users added above into the central routing index.
        $provisioner->syncRouting($dbm, $acme);

        $globex = Tenant::firstOrCreate(
            ['slug' => 'globex'],
            ['name' => 'Globex', 'description' => 'Second demo tenant']
        );

        $provisioner->provisionIsolated($globex, $dbm, $lifecycle);
    }
}