<?php

namespace Database\Seeders\Realistic;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * People of one tenant: the org chart (pure `plan()`), then users, roles and — for HRMS tenants — departments,
 * designations, locations, employees and status history (`persist()`).
 *
 * The chart is built parents-first, so `manager` always points at a lower index: no cycles by construction.
 */
final class PeopleSeeder
{
    public function __construct(private readonly Bulk $bulk) {}

    /**
     * @param  array<string, mixed>  $bp
     * @return array{people: list<array<string, mixed>>, departments: list<array{name: string, code: string}>}
     */
    public function plan(array $bp, Timeline $tl): array
    {
        Rng::seed($bp['index'], 'people');
        $n = (int) $bp['users'];
        $hrms = in_array('hrms', $bp['products'], true);
        $tms = in_array('tms', $bp['products'], true);

        $deptPool = NameBank::DEPARTMENTS[$bp['dept_set']];
        $deptCount = max(5, min(count($deptPool), (int) round($n / 14)));
        // People & Culture and Finance always exist (HR and payroll roles hang off them); the rest fill by weight.
        $core = array_values(array_filter($deptPool, fn ($d) => ! in_array($d[1], ['HR', 'FIN'], true)));
        $musts = array_values(array_filter($deptPool, fn ($d) => in_array($d[1], ['HR', 'FIN'], true)));
        $departments = array_map(fn ($d) => ['name' => $d[0], 'code' => $d[1]], array_merge(array_slice($core, 0, max(3, $deptCount - count($musts))), $musts));
        $deptCount = count($departments);

        // 1. names, unique within the tenant
        $names = [];
        $used = [];
        $guard = 0;
        while (count($names) < $n && $guard++ < $n * 20) {
            $f = Rng::pick(NameBank::FIRST_NAMES);
            $l = Rng::pick(NameBank::LAST_NAMES);
            if (isset($used["$f $l"])) {
                continue;
            }
            $used["$f $l"] = true;
            $names[] = [$f, $l];
        }

        // 2. department weights: product/engineering-type departments carry more people
        $weights = [];
        foreach ($departments as $i => $d) {
            $weights[$i] = in_array($d['code'], ['ENG', 'OPS', 'STO', 'CLN', 'PRO', 'ACD', 'ADV'], true) ? 5 : (in_array($d['code'], ['HR', 'FIN', 'LGL'], true) ? 1.5 : 3);
        }

        $people = [];
        $deptHead = [];
        $domain = $bp['domain'];
        $mk = function (int $idx, int $dept, int $level, ?int $manager) use (&$people, $names, $domain, $departments) {
            [$f, $l] = $names[$idx];
            $local = Str::lower(Str::ascii($f).'.'.Str::ascii($l));
            $dupes = collect($people)->where('local', $local)->count();
            $people[$idx] = [
                'idx' => $idx, 'first' => $f, 'last' => $l, 'name' => "$f $l", 'local' => $local,
                'email' => $local.($dupes ? $dupes + 1 : '').'@'.$domain,
                'dept' => $dept, 'dept_code' => $departments[$dept]['code'], 'level' => $level, 'manager' => $manager, 'roles' => [],
            ];
        };

        // 3. owner, department heads, team leads, members
        $mk(0, 0, 4, null);
        $people[0]['title'] = 'Founder & Chief Executive Officer';
        for ($d = 0; $d < $deptCount; $d++) {
            $idx = $d + 1;
            if ($idx >= $n) {
                break;
            }
            $mk($idx, $d, 4, 0);
            $deptHead[$d] = $idx;
        }
        $next = count($people);
        $leadsByDept = [];
        $perDeptLeadCount = max(1, (int) floor(($n - $next) / ($deptCount * 9)));
        // leads first so members can report to them
        for ($d = 0; $d < $deptCount && $next < $n; $d++) {
            for ($k = 0; $k < $perDeptLeadCount && $next < $n && ($n - $next) > $deptCount; $k++) {
                $mk($next, $d, 3, $deptHead[$d] ?? 0);
                $leadsByDept[$d][] = $next++;
            }
        }
        $rr = [];
        while ($next < $n) {
            $d = Rng::weighted($weights);
            $leads = $leadsByDept[$d] ?? [];
            $rr[$d] = ($rr[$d] ?? -1) + 1;
            $manager = $leads ? $leads[$rr[$d] % count($leads)] : ($deptHead[$d] ?? 0);
            $mk($next, $d, Rng::chance(0.22) ? 2 : 1, $manager);
            $next++;
        }

        // 4. titles
        foreach ($people as $i => &$p) {
            if (isset($p['title'])) {
                continue;
            }
            $dept = $departments[$p['dept']];
            $roles = NameBank::ROLES_BY_DEPT_CODE[$dept['code']] ?? [$dept['name'].' Specialist'];
            $role = Rng::pick($roles);
            $tpl = Rng::pick(NameBank::DESIGNATION_TITLES[$p['level']]);
            $p['title'] = str_replace(['{dept}', '{role}'], [$dept['name'], $role], $tpl);
        }
        unset($p);

        // 5. roles
        $hrDept = array_search('HR', array_column($departments, 'code'), true);
        $finDept = array_search('FIN', array_column($departments, 'code'), true);
        $payrollAssigned = $auditorAssigned = false;
        $hrExec = 0;
        foreach ($people as $i => &$p) {
            $roles = [];
            if ($i === 0) {
                $roles = ['admin'];
            } elseif ($p['level'] >= 3) {
                $roles = ['manager'];
                if ($hrms && $p['dept'] === $hrDept && $p['level'] === 4) {
                    $roles = ['hr_manager', 'manager'];
                }
                if ($hrms && $p['dept'] === $finDept && $p['level'] === 4) {
                    $roles = ['finance_admin', 'payroll_manager', 'manager'];
                    $payrollAssigned = true;
                }
            } elseif ($hrms && $p['dept'] === $hrDept && $hrExec < 3) {
                $roles = ['hr_executive'];
                $hrExec++;
            } elseif ($hrms && $p['dept'] === $finDept && ! $auditorAssigned && $p['level'] === 2) {
                $roles = ['auditor'];
                $auditorAssigned = true;
            } elseif ($hrms) {
                $roles = ['employee'];
            }
            if ($tms && $i !== 0 && ! in_array('manager', $roles, true)) {
                $roles[] = Rng::chance(0.78) ? 'editor' : 'viewer';
            }
            if ($tms && ! $hrms && $roles === []) {
                $roles = ['editor'];
            }
            $p['roles'] = $roles;
        }
        unset($p);

        // 6. employment
        $createdAt = $tl->created;
        $end = $tl->end;
        $windowDays = $tl->days();
        $exitedBudget = $windowDays > 45 ? (int) round($n * 0.05) : 0;
        foreach ($people as $i => &$p) {
            if ($i === 0) {
                $p['joining'] = $createdAt->subDays(mt_rand(365, 1800))->startOfDay();
            } elseif ($windowDays > 30 && Rng::chance(0.30)) {
                $p['joining'] = CarbonImmutable::instance(Rng::between($createdAt->addDay(), $end->subDays(3)))->startOfDay();
            } else {
                $p['joining'] = $createdAt->subDays(mt_rand(10, 2000))->startOfDay();
            }
            $p['status'] = 'active';
            $p['exit_date'] = null;
            $p['exited_reason'] = null;
            if (abs($end->diffInDays($p['joining'])) < 90 && $p['joining']->lte($end) && Rng::chance(0.6)) {
                $p['status'] = 'probation';
            }
        }
        unset($p);

        // exits: only junior, non-lead staff, never the owner or a head
        $candidates = array_keys(array_filter($people, fn ($p) => $p['level'] <= 2 && $p['status'] === 'active' && $p['joining']->lt($end->subDays(150))));
        shuffle($candidates);
        foreach (array_slice($candidates, 0, $exitedBudget) as $k => $i) {
            $kind = ['exited', 'exited', 'terminated', 'on_notice', 'suspended'][$k % 5];
            $people[$i]['status'] = $kind;
            if (in_array($kind, ['exited', 'terminated'], true)) {
                $people[$i]['exit_date'] = CarbonImmutable::instance(Rng::between($people[$i]['joining']->addDays(120), $end->subDay()))->startOfDay();
                $people[$i]['exited_reason'] = $kind === 'terminated' ? 'Performance and conduct review' : Rng::pick(['Better opportunity', 'Relocation', 'Higher studies', 'Career change', 'Personal reasons']);
            } elseif ($kind === 'on_notice') {
                $people[$i]['exit_date'] = $end->addDays(mt_rand(10, 45))->startOfDay();
            }
        }

        return ['people' => array_values($people), 'departments' => $departments];
    }

    /**
     * Create users (+ roles) and, for HRMS tenants, the org tables and employee records.
     *
     * @param  array{people: list<array<string, mixed>>, departments: list<array{name: string, code: string}>}  $plan
     * @return list<array<string, mixed>> people, each gaining `user_id` (and `employee_id` for HRMS)
     */
    public function persist(array $plan, array $bp, Timeline $tl, string $passwordHash, int $ownerId): array
    {
        $people = $plan['people'];
        $hrms = in_array('hrms', $bp['products'], true);
        $roleIds = DB::table('roles')->pluck('id', 'slug')->all();

        // users: the owner exists (claimed); everyone else gets the next ids
        $userRows = [];
        $roleRows = [];
        foreach ($people as $i => &$p) {
            $createdAt = $p['joining']->lt($tl->created) ? $tl->created : $p['joining']->setTime(9, mt_rand(0, 59));
            $p['user_created_at'] = $createdAt;
            if ($i === 0) {
                $p['user_id'] = $ownerId;
            } else {
                $p['user_id'] = $this->bulk->id('users');
                $userRows[] = [
                    'id' => $p['user_id'], 'name' => $p['name'], 'email' => $p['email'], 'password' => $passwordHash,
                    'email_verified_at' => $createdAt, 'is_default' => false, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                ];
            }
            foreach ($p['roles'] as $slug) {
                if (isset($roleIds[$slug])) {
                    $roleRows[$p['user_id'].':'.$roleIds[$slug]] = ['role_id' => $roleIds[$slug], 'user_id' => $p['user_id']];
                }
            }
        }
        unset($p);
        $this->bulk->insert('users', $userRows);
        DB::table('role_user')->where('user_id', $ownerId)->delete();
        $this->bulk->insert('role_user', array_values($roleRows));

        if ($hrms) {
            $people = $this->persistHr($people, $plan['departments'], $bp, $tl);
        }

        return $people;
    }

    /** @return list<array<string, mixed>> */
    private function persistHr(array $people, array $departments, array $bp, Timeline $tl): array
    {
        Rng::seed($bp['index'], 'hr-core');
        [$cc, $country, $tz, $currency, $state, $city] = $bp['country'];
        $now = $tl->now;

        // departments (reuse the provisioned engineering/sales/operations rows by slug)
        $existing = DB::table('departments')->pluck('id', 'slug')->all();
        $deptIds = [];
        $deptRows = [];
        foreach ($departments as $i => $d) {
            $slug = Str::slug($d['name']);
            if (isset($existing[$slug])) {
                $deptIds[$i] = $existing[$slug];

                continue;
            }
            $deptIds[$i] = $this->bulk->id('departments');
            $deptRows[] = ['id' => $deptIds[$i], 'name' => $d['name'], 'slug' => $slug, 'code' => $d['code'], 'description' => $d['name'].' team', 'is_active' => true, 'position' => $i + 1, 'created_at' => $tl->created, 'updated_at' => $tl->created];
        }
        $this->bulk->insert('departments', $deptRows);

        // designations: one row per distinct title
        $existingDes = DB::table('designations')->pluck('id', 'slug')->all();
        $desIds = [];
        $desRows = [];
        foreach ($people as $p) {
            $slug = Str::slug($p['title']);
            if (isset($desIds[$slug]) || isset($existingDes[$slug])) {
                $desIds[$slug] ??= $existingDes[$slug];

                continue;
            }
            $desIds[$slug] = $this->bulk->id('designations');
            $desRows[] = ['id' => $desIds[$slug], 'name' => $p['title'], 'slug' => $slug, 'code' => strtoupper(Str::limit(Str::replace('-', '', $slug), 8, '')), 'level' => $p['level'], 'department_id' => $deptIds[$p['dept']], 'is_active' => true, 'position' => count($desRows) + 5, 'created_at' => $tl->created, 'updated_at' => $tl->created];
        }
        $this->bulk->insert('designations', $desRows);

        // locations: provisioned head office + branch offices
        $locIds = DB::table('locations')->orderBy('id')->pluck('id')->all();
        $branchRows = [];
        foreach ([[$city.' Campus', $city], ['Remote Hub', $city]] as $k => [$label, $cityName]) {
            $id = $this->bulk->id('locations');
            $branchRows[] = ['id' => $id, 'name' => $label, 'slug' => Str::slug($label).'-'.$bp['index'], 'address_line1' => mt_rand(10, 400).' Business Park', 'city' => $cityName, 'state' => $state, 'postal_code' => (string) mt_rand(100000, 999999), 'country' => $cc, 'timezone' => $tz, 'is_geo_fenced' => false, 'is_active' => true, 'position' => 10 + $k, 'created_at' => $tl->created, 'updated_at' => $tl->created];
            $locIds[] = $id;
        }
        $this->bulk->insert('locations', $branchRows);

        $employmentTypes = DB::table('employment_types')->pluck('id', 'slug')->all();
        $shifts = DB::table('attendance_shifts')->orderBy('id')->pluck('id')->all();
        $empIds = [];
        $rows = [];
        $history = [];
        foreach ($people as $i => &$p) {
            $p['employee_id'] = $empIds[$i] = $this->bulk->id('employees');
            $joining = $p['joining'];
            $type = $p['level'] === 1 && Rng::chance(0.1) ? 'intern' : Rng::weighted(['full-time' => 86, 'part-time' => 3, 'contractor' => 9]);
            $type = (string) $type;
            $p['employment_type'] = $type;
            $dob = CarbonImmutable::create(mt_rand(1970, 2002), mt_rand(1, 12), mt_rand(1, 28));
            $created = $p['user_created_at'];
            $p['work_mode'] = (string) Rng::weighted(['office' => 55, 'hybrid' => 33, 'remote' => 12]);
            $p['shift_id'] = $bp['dept_set'] === 'ops' && $p['dept_code'] === 'OPS' && Rng::chance(0.15) ? $shifts[1] : ($shifts[0] ?? null);
            $p['location_id'] = $p['work_mode'] === 'remote' ? $locIds[count($locIds) - 1] : Rng::pick(array_slice($locIds, 0, count($locIds) - 1));
            $rows[] = [
                'id' => $p['employee_id'], 'user_id' => $p['user_id'], 'employee_code' => sprintf('EMP-%04d', $i + 1), 'name' => $p['name'],
                'personal_email' => Str::lower(Str::ascii($p['first'].mt_rand(10, 99))).'@personalmail.test',
                'phone' => '+'.mt_rand(10, 99).' '.mt_rand(70000, 99999).' '.mt_rand(10000, 99999),
                'date_of_birth' => $dob->toDateString(), 'marital_status' => Rng::pick(['single', 'married', 'married', 'single']),
                'nationality' => $cc, 'address_line1' => mt_rand(1, 300).' '.Rng::pick(['Lake View', 'Green Park', 'Hill Road', 'Sunrise Apartments']),
                'city' => $city, 'state' => $state, 'postal_code' => (string) mt_rand(100000, 999999), 'country' => $cc,
                'emergency_contact_name' => Rng::pick(NameBank::FIRST_NAMES).' '.$p['last'], 'emergency_contact_phone' => '+'.mt_rand(10, 99).' '.mt_rand(70000, 99999).' '.mt_rand(10000, 99999),
                'emergency_contact_relation' => Rng::pick(['Spouse', 'Parent', 'Sibling']),
                'joining_date' => $joining->toDateString(),
                'probation_end_date' => $joining->addDays(90)->toDateString(),
                'confirmation_date' => $p['status'] === 'probation' ? null : $joining->addDays(90)->toDateString(),
                'employment_type_id' => $employmentTypes[$type] ?? null, 'designation' => $p['title'],
                'manager_id' => $p['manager'] === null ? null : $empIds[$p['manager']],
                'work_mode' => $p['work_mode'], 'status' => $p['status'],
                'exit_date' => $p['exit_date']?->toDateString(), 'exited_reason' => $p['exited_reason'],
                'created_by' => 1, 'created_at' => $created, 'updated_at' => $now,
                'department_id' => $deptIds[$p['dept']], 'designation_id' => $desIds[Str::slug($p['title'])] ?? null,
                'location_id' => $p['location_id'], 'shift_id' => $p['shift_id'],
            ];

            $history[] = ['employee_id' => $p['employee_id'], 'from_status' => null, 'to_status' => $p['status'] === 'probation' ? 'probation' : 'active', 'effective_date' => $joining->toDateString(), 'reason' => 'Joined the company', 'note' => null, 'actor_user_id' => null, 'created_at' => $created];
            if (! in_array($p['status'], ['probation', 'active'], true)) {
                $history[] = ['employee_id' => $p['employee_id'], 'from_status' => 'active', 'to_status' => $p['status'], 'effective_date' => ($p['exit_date'] ?? $now->subDays(10))->toDateString(), 'reason' => $p['exited_reason'] ?? 'Status change', 'note' => null, 'actor_user_id' => null, 'created_at' => $p['exit_date'] ?? $now->subDays(10)];
            }
        }
        unset($p);

        // shift_id / location FKs are plain columns here (raw insert), so one statement is fine
        $this->bulk->insert('employees', $rows);
        $this->bulk->insert('employee_status_history', $history);

        // department heads
        foreach ($people as $p) {
            if ($p['level'] === 4 && $p['idx'] !== 0) {
                DB::table('departments')->where('id', $deptIds[$p['dept']])->update(['head_employee_id' => $p['employee_id']]);
            }
        }

        foreach ($people as &$p) {
            $p['department_id'] = $deptIds[$p['dept']];
        }
        unset($p);

        return $people;
    }
}
