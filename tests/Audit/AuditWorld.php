<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The data the form audit submits against: one realistic enterprise tenant (both products, every module) with a
 * user per role, plus a copy-on-restore snapshot so every submission starts from identical data.
 */
final class AuditWorld
{
    public const SLUG = 'pembroke-hospitality-ltd';

    /** Perspectives: label => [tenant role slug, or null for non-tenant perspectives]. */
    public const ROLES = [
        'admin' => 'admin',
        'editor' => 'editor',
        'viewer' => 'viewer',
        'project_lead' => null,
        'manager' => 'manager',
        'hr_manager' => 'hr_manager',
        'payroll_manager' => 'payroll_manager',
        'employee' => 'employee',
        'hr_executive' => 'hr_executive',
        'finance_admin' => 'finance_admin',
        'auditor' => 'auditor',
    ];

    public Tenant $tenant;

    /** @var array<string, int> perspective => tenant-local user id */
    public array $users = [];

    public SystemUser $superAdmin;

    private string $snapshot;

    private string $storageSnapshot;

    public function __construct(private readonly string $dir, private readonly TenantDatabaseManager $dbm) {}

    public function build(): void
    {
        config([
            'demo_seed.users' => ['min' => 24, 'max' => 24],
            'demo_seed.projects' => ['min' => 2, 'max' => 2],
            'demo_seed.tasks_per_project' => ['min' => 6, 'max' => 6],
            'demo_seed.attendance_days' => 5,
            'demo_seed.payroll_months' => 1,
        ]);

        app(RealisticDemoSeeder::class)->run(slugs: [self::SLUG]);

        $this->dbm->connectSystem();
        $this->tenant = Tenant::where('slug', self::SLUG)->firstOrFail();
        $this->superAdmin = SystemUser::where('is_super_admin', true)->firstOrFail();

        $this->dbm->using($this->tenant, function (): void {
            $this->pickUsers();
        });
        $this->dbm->connectSystem();

    }

    /** Take the snapshot every later restore() returns to (call after any bootstrapping). */
    public function freeze(): void
    {
        $this->dbm->connectSystem();
        DB::purge('iso_system');
        DB::purge('tenant');
        $this->snapshot = sys_get_temp_dir().'/flowsync-audit-snapshot-'.Str::random(6);
        File::copyDirectory($this->dir, $this->snapshot);

        // Uploaded files live in fake storage; a delete form removes them, so they are put back with the data.
        $this->storageSnapshot = sys_get_temp_dir().'/flowsync-audit-files-'.Str::random(6);
        File::ensureDirectoryExists(storage_path('framework/testing/disks'));
        File::copyDirectory(storage_path('framework/testing/disks'), $this->storageSnapshot);
        register_shutdown_function(function () {
            File::deleteDirectory($this->snapshot);
            File::deleteDirectory($this->storageSnapshot);
        });
    }

    /** Put every database file back exactly as it was after build(). */
    public function restore(): void
    {
        DB::purge('iso_system');
        DB::purge('tenant');
        foreach (File::files($this->snapshot) as $file) {
            copy($file->getPathname(), $this->dir.'/'.$file->getFilename());
        }
        File::deleteDirectory(storage_path('framework/testing/disks'));
        File::copyDirectory($this->storageSnapshot, storage_path('framework/testing/disks'));
    }

    public function user(string $perspective): User
    {
        return User::findOrFail($this->users[$perspective]);
    }

    /**
     * One distinct user per perspective, each holding EXACTLY that role: the seeded people carry several roles at once
     * (an employee is also an editor in a tenant with projects), which would hide every permission difference.
     */
    private function pickUsers(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $held = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('role_user.user_id', 'roles.slug')->get()->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('slug')->all());
        $withEmployee = DB::table('employees')->whereNotNull('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $managerUsers = DB::table('employees as m')->join('employees as r', 'r.manager_id', '=', 'm.id')->whereNotNull('m.user_id')
            ->distinct()->pluck('m.user_id')->map(fn ($v) => (int) $v)->all();
        $projectMembers = DB::table('project_members')->join('project_roles', 'project_roles.id', '=', 'project_members.project_role_id')
            ->select('project_members.user_id', 'project_roles.slug as project_role')->get();

        $defaultUserId = (int) (DB::table('users')->where('is_default', true)->value('id') ?? 0);
        $used = [$defaultUserId];

        // Admin = the tenant owner (the only admin the seeder creates).
        $admin = (int) DB::table('role_user')->where('role_id', $roleIds['admin'])->value('user_id');
        $this->users['admin'] = $admin;
        $used[] = $admin;

        $pick = function (string $slug, array $prefer = []) use (&$used, $held, $withEmployee): int {
            $pool = collect($prefer)->filter(fn ($id) => ! in_array($id, $used, true))->values();
            if ($pool->isEmpty()) {
                $pool = collect($held)->filter(fn ($set, $id) => in_array($slug, $set, true) && ! in_array((int) $id, $used, true))
                    ->sortBy(fn ($set) => count($set))->keys()->map(fn ($v) => (int) $v)->values();
            }
            if ($pool->isEmpty()) {
                $pool = collect($withEmployee)->filter(fn ($id) => ! in_array($id, $used, true))->values();
            }
            $id = (int) $pool->first();
            $used[] = $id;

            return $id;
        };

        $leads = $projectMembers->where('project_role', 'lead')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $devs = $projectMembers->where('project_role', 'developer')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $viewers = $projectMembers->where('project_role', 'viewer')->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        $this->users['project_lead'] = $pick('editor', $leads);
        $this->users['editor'] = $pick('editor', $devs);
        $this->users['viewer'] = $pick('viewer', $viewers);
        $this->users['manager'] = $pick('manager', $managerUsers);
        $this->users['hr_manager'] = $pick('hr_manager');
        $this->users['finance_admin'] = $pick('finance_admin');
        $this->users['payroll_manager'] = $pick('payroll_manager');
        $this->users['hr_executive'] = $pick('hr_executive');
        $this->users['auditor'] = $pick('auditor');
        $this->users['employee'] = $pick('employee');

        // Strip each perspective down to its single role (the project lead keeps a tenant `editor` so they can reach the app).
        foreach (self::ROLES as $label => $slug) {
            $role = $slug ?? 'editor';
            if ($label === 'admin') {
                continue;
            }
            DB::table('role_user')->where('user_id', $this->users[$label])->delete();
            DB::table('role_user')->insert(['user_id' => $this->users[$label], 'role_id' => $roleIds[$role]]);
        }
    }

    public static function configure(string $dir): void
    {
        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', $dir);
    }
}
