<?php

namespace Database\Seeders\Realistic;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR data of one tenant, gated by the modules its plans include (a starter tenant gets employees, org and
 * onboarding/offboarding only — never leave or payroll rows it could not reach). Order matters for consistency:
 * holidays → leave → attendance (leave and holidays show up as attendance statuses) → expenses → assets →
 * lifecycle → compensation → payroll (uses attendance/leave) → performance → talent.
 */
final class HrmsSeeder
{
    private const FX = ['USD' => 1.0, 'INR' => 83.0, 'GBP' => 0.8, 'EUR' => 0.92, 'AED' => 3.67, 'SGD' => 1.35, 'AUD' => 1.5, 'CAD' => 1.36];

    private const LEVEL_BANDS = [1 => [38000, 62000], 2 => [62000, 98000], 3 => [92000, 135000], 4 => [125000, 230000]];

    private TenantRun $run;

    private Bulk $bulk;

    /** @var array<string, string> date => holiday name */
    private array $holidays = [];

    /** @var array<string, array<string, true>> employee_id => [date => true] approved leave days */
    private array $leaveDays = [];

    /** @var array<int, array<string, mixed>> employee_id => person */
    private array $byEmp = [];

    /** @var array<int, int> employee_id => current salary structure row id */
    private array $salaryRow = [];

    /** @var array<int, array{gross: float, ctc: float}> */
    private array $salary = [];

    public function seed(TenantRun $run): void
    {
        $this->run = $run;
        $this->bulk = $run->bulk;
        foreach ($run->people as $p) {
            $this->byEmp[$p['employee_id']] = $p;
        }

        $m = fn (string $key) => $run->module($key);

        $m('hrms.holidays') && $this->holidays();
        $m('hrms.leave') && $this->leave();
        $m('hrms.attendance') && $this->attendance();
        $m('hrms.expenses') && $this->expenses();
        $m('hrms.assets') && $this->assets();
        $m('hrms.onboarding') && $this->onboarding();
        $m('hrms.offboarding') && $this->offboarding();
        $m('hrms.compensation') && $this->compensation();
        $m('hrms.payroll') && $this->payroll();
        $m('hrms.performance') && $this->performance();
        $m('hrms.talent') && $this->talent();
    }

    // ---------------------------------------------------------------- helpers

    private function tl(): Timeline
    {
        return $this->run->tl;
    }

    /** People whose employment covers [$from, $to]: joined by `$to`, not exited before `$from`. */
    private function working(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(array_filter($this->run->people, function ($p) use ($from, $to) {
            if ($p['joining']->gt($to)) {
                return false;
            }
            if ($p['exit_date'] !== null && in_array($p['status'], ['exited', 'terminated'], true) && $p['exit_date']->lt($from)) {
                return false;
            }

            return true;
        }));
    }

    private function managerUserId(array $p): int
    {
        $m = $p['manager'] ?? null;

        return $m !== null ? $this->run->people[$m]['user_id'] : $this->run->people[0]['user_id'];
    }

    private function managerEmpId(array $p): ?int
    {
        $m = $p['manager'] ?? null;

        return $m !== null ? $this->run->people[$m]['employee_id'] : null;
    }

    /** Approval + one step; returns the approval id. Rows are collected and inserted by the caller via flushApprovals(). */
    private array $approvalRows = [];

    private array $stepRows = [];

    private function approval(string $type, int $subjectId, string $label, string $action, string $domain, array $requester, string $status, $createdAt, ?array $meta, $resolvedAt = null): int
    {
        $id = $this->bulk->id('approvals');
        $approverUser = $this->managerUserId($requester);
        $this->approvalRows[] = [
            'id' => $id, 'approvable_type' => $type, 'approvable_id' => $subjectId, 'subject' => $label, 'action' => $action, 'status' => $status,
            'requested_by_user_id' => $requester['user_id'], 'requested_by_employee_id' => $requester['employee_id'], 'current_step' => 1,
            'due_at' => null, 'resolved_at' => $status === 'pending' ? null : $resolvedAt, 'resolved_by_user_id' => $status === 'pending' ? null : $approverUser,
            'decision_note' => $status === 'rejected' ? 'Not possible in this period; please re-plan.' : null, 'meta' => $meta ? json_encode($meta) : null,
            'created_at' => $createdAt, 'updated_at' => $resolvedAt ?? $createdAt, 'deleted_at' => null, 'domain' => $domain, 'reminded_at' => null, 'escalated_at' => null, 'resubmission_of_id' => null,
        ];
        $this->stepRows[] = [
            'approval_id' => $id, 'step_order' => 1, 'approver_type' => 'manager', 'approver_role_id' => null, 'approver_user_id' => $approverUser,
            'approver_employee_id' => $this->managerEmpId($requester), 'status' => $status === 'cancelled' ? 'skipped' : $status,
            'acted_at' => in_array($status, ['approved', 'rejected'], true) ? $resolvedAt : null, 'acted_by_user_id' => in_array($status, ['approved', 'rejected'], true) ? $approverUser : null,
            'note' => null, 'created_at' => $createdAt, 'updated_at' => $resolvedAt ?? $createdAt, 'stage' => 1, 'mode' => 'any', 'sla_hours' => null, 'acted_for_user_id' => null,
        ];

        return $id;
    }

    private function flushApprovals(): void
    {
        $this->bulk->insert('approvals', $this->approvalRows);
        $this->bulk->insert('approval_steps', $this->stepRows);
        $this->approvalRows = $this->stepRows = [];
    }

    // ---------------------------------------------------------------- holidays

    private function holidays(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-holidays');
        $tl = $this->tl();
        [$cc, $country] = $this->run->bp['country'];
        $calendar = $this->bulk->id('holiday_calendars');
        $years = array_unique([$tl->end->year - 1, $tl->end->year]);
        $national = ['IN' => ['Republic Day', '01-26', 'Independence Day', '08-15'], 'US' => ['Memorial Day', '05-25', 'Independence Day', '07-04'], 'GB' => ['Early May Bank Holiday', '05-04', 'Summer Bank Holiday', '08-31']][$cc] ?? ['National Day', '08-15', 'Founders Day', '03-10'];

        DB::table('holiday_calendars')->update(['is_default' => false]);
        $this->bulk->insert('holiday_calendars', [['id' => $calendar, 'name' => "$country Holidays", 'slug' => 'holidays-'.Str::lower($cc).'-'.$this->run->bp['index'], 'country' => $cc, 'region' => null, 'description' => "Official and company holidays for $country offices.", 'is_default' => true, 'is_active' => true, 'position' => 1, 'created_at' => $tl->created, 'updated_at' => $tl->created]]);

        $rows = [];
        foreach ($years as $y) {
            foreach ([['New Year\'s Day', '01-01', 'public'], [$national[0], $national[1], 'public'], ['Labour Day', '05-01', 'public'], [$national[2], $national[3], 'public'], ['Gandhi Jayanti / Peace Day', '10-02', 'restricted'], ['Founders Day', '11-14', 'public'], ['Christmas Day', '12-25', 'public'], ['Year-end Shutdown', '12-31', 'optional']] as [$name, $md, $type]) {
                $date = CarbonImmutable::parse("$y-$md");
                $rows[] = ['calendar_id' => $calendar, 'name' => $name, 'date' => $date->toDateString(), 'type' => $type, 'is_recurring' => false, 'description' => null, 'created_at' => $tl->created, 'updated_at' => $tl->created];
                if ($type === 'public') {
                    $this->holidays[$date->toDateString()] = $name;
                }
            }
        }
        $this->bulk->insert('holidays', $rows);

        $links = [];
        foreach ($this->run->people as $p) {
            $links[] = ['employee_id' => $p['employee_id'], 'calendar_id' => $calendar, 'effective_from' => $p['joining']->toDateString(), 'effective_to' => null];
        }
        $this->bulk->insert('employee_holiday_calendars', $links);
    }

    // ---------------------------------------------------------------- leave

    private function leave(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-leave');
        $tl = $this->tl();
        $end = $tl->end;
        $types = DB::table('leave_types')->get()->keyBy('slug');
        $year = $end->year;
        $yearStart = CarbonImmutable::create($year, 1, 1);

        $requests = $days = $perEmployee = [];
        $reqIds = [];
        foreach ($this->working($tl->created, $end) as $p) {
            $from = $p['joining']->gt($tl->created) ? $p['joining'] : $tl->created;
            $to = $p['exit_date'] && $p['exit_date']->lt($end) ? $p['exit_date'] : $end;
            if ($from->diffInDays($to, true) < 20) {
                continue;
            }
            $n = (int) Rng::weighted([0 => 25, 1 => 35, 2 => 25, 3 => 15]);
            $cursor = $from->addDays(mt_rand(7, 25));
            for ($k = 0; $k < $n; $k++) {
                $slug = (string) Rng::weighted(['annual-leave' => 58, 'sick-leave' => 33, 'unpaid-leave' => 4, 'paternity-leave' => 2, 'maternity-leave' => 3]);
                $len = match ($slug) {
                    'annual-leave' => mt_rand(1, 6), 'sick-leave' => mt_rand(1, 3), 'unpaid-leave' => mt_rand(1, 3), 'paternity-leave' => 10, default => 20,
                };
                $start = $cursor->addDays(mt_rand(3, 40));
                while ($start->isWeekend()) {
                    $start = $start->addDay();
                }
                if ($start->gt($end->addDays(35)) || $start->gt($to->addDays(35))) {
                    break;
                }
                $stop = $start->addDays($len - 1 + (int) floor($len / 5) * 2);
                $cursor = $stop->addDay();

                $workDates = [];
                for ($d = $start; $d->lte($stop); $d = $d->addDay()) {
                    if (! $d->isWeekend() && ! isset($this->holidays[$d->toDateString()])) {
                        $workDates[] = $d;
                    }
                }
                if ($workDates === []) {
                    continue;
                }

                $created = $slug === 'sick-leave' ? $start->setTime(8, 30) : $start->subDays(mt_rand(2, 20))->setTime(11, 0);
                if ($created->lt($p['user_created_at'])) {
                    $created = $p['user_created_at']->addHour();
                }
                if ($created->gt($end)) {
                    continue; // a request cannot be filed after the data window closes
                }
                $past = $stop->lt($end->subDays(2));
                $status = $past ? (string) Rng::weighted(['approved' => 82, 'rejected' => 8, 'cancelled' => 10]) : (string) Rng::weighted(['pending' => 70, 'approved' => 30]);
                $resolved = $status === 'pending' ? null : $created->addHours(mt_rand(3, 60));
                if ($resolved && $resolved->gt($end)) {
                    $resolved = $end->subMinutes(mt_rand(5, 90));
                }
                if ($resolved && $resolved->lte($created)) {
                    $resolved = $created->addMinutes(30);
                }

                $id = $this->bulk->id('leave_requests');
                $approval = $this->approval('App\\Models\\Hrms\\Leave\\LeaveRequest', $id, 'Leave request', 'leave.request', 'leave', $p, $status, $created, ['work_dates' => $start->toDateString().' to '.$stop->toDateString(), 'total_days' => count($workDates)], $resolved);
                $requests[] = [
                    'id' => $id, 'employee_id' => $p['employee_id'], 'leave_type_id' => $types[$slug]->id, 'from_date' => $start->toDateString(), 'to_date' => $stop->toDateString(),
                    'total_days' => count($workDates), 'reason' => Rng::pick(['Family function', 'Medical appointment', 'Personal errands', 'Travel plans', 'Feeling unwell', 'Festival break', 'Rest and recovery']),
                    'status' => $status, 'approval_id' => $approval, 'decided_at' => $resolved, 'decided_by_user_id' => $resolved ? $this->managerUserId($p) : null,
                    'cancel_reason' => $status === 'cancelled' ? 'Plans changed' : null, 'created_by' => $p['user_id'], 'created_at' => $created, 'updated_at' => $resolved ?? $created, 'deleted_at' => null,
                ];
                foreach ($workDates as $d) {
                    $days[] = ['leave_request_id' => $id, 'date' => $d->toDateString(), 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false];
                    if ($status === 'approved' && $d->lt($end)) {
                        $this->leaveDays[$p['employee_id']][$d->toDateString()] = true;
                    }
                }
                if ($status === 'approved' && $start->year === $year) {
                    $perEmployee[$p['employee_id']][$slug] = ($perEmployee[$p['employee_id']][$slug] ?? 0) + count(array_filter($workDates, fn ($d) => $d->year === $year));
                }
            }
        }
        $this->flushApprovals(); // leave_requests.approval_id references approvals
        $this->bulk->insert('leave_requests', $requests);
        $this->bulk->insert('leave_request_days', $days);

        // balances: annual credit prorated for in-year joiners, sick accrues monthly, availed = approved days this year
        $balances = [];
        foreach ($this->run->people as $p) {
            if ($p['joining']->gt($end)) {
                continue;
            }
            $monthsIn = $p['joining']->gt($yearStart) ? max(1, 12 - $p['joining']->month + 1) : 12;
            $elapsed = max(1, (int) min($monthsIn, $end->month - ($p['joining']->gt($yearStart) ? $p['joining']->month - 1 : 0)));
            foreach (['annual-leave' => [round(30 * $monthsIn / 12, 1), $p['joining']->lt($yearStart) ? mt_rand(0, 5) : 0], 'sick-leave' => [min(12, $elapsed), 0]] as $slug => [$accrued, $carried]) {
                $availed = (float) ($perEmployee[$p['employee_id']][$slug] ?? 0);
                $balances[] = [
                    'employee_id' => $p['employee_id'], 'leave_type_id' => $types[$slug]->id, 'year' => $year, 'opening' => 0, 'accrued' => $accrued, 'availed' => $availed,
                    'encashed' => 0, 'lapsed' => 0, 'carried_forward' => $carried, 'adjusted' => 0, 'balance' => round($accrued + $carried - $availed, 2),
                    'created_at' => $tl->created, 'updated_at' => $end,
                ];
            }
        }
        $this->bulk->insert('leave_balances', $balances);
    }

    // ---------------------------------------------------------------- attendance

    private function attendance(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-attendance');
        $tl = $this->tl();
        $last = $tl->end->subDay()->startOfDay();
        $first = $last->subDays((int) config('demo_seed.attendance_days') - 1);
        if ($first->lt($tl->created->startOfDay())) {
            $first = $tl->created->startOfDay();
        }
        $shift = DB::table('attendance_shifts')->get()->keyBy('id');
        $rows = [];
        $punches = [];

        foreach ($this->working($first, $last) as $p) {
            $s = $shift[$p['shift_id']] ?? $shift->first();
            [$sh, $sm] = array_map('intval', explode(':', substr($s->start_time, 0, 5)));
            $grace = (int) $s->grace_minutes;
            $from = $p['joining']->startOfDay()->gt($first) ? $p['joining']->startOfDay() : $first;
            $to = $p['exit_date'] && $p['exit_date']->lte($last) ? $p['exit_date']->subDay() : $last;
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                $date = $d->toDateString();
                $status = null;
                $firstIn = $lastOut = null;
                $worked = $late = $early = $ot = 0;
                if ($d->isWeekend()) {
                    $status = 'week_off';
                } elseif (isset($this->holidays[$date])) {
                    $status = 'holiday';
                } elseif (isset($this->leaveDays[$p['employee_id']][$date])) {
                    $status = 'leave';
                } else {
                    $roll = mt_rand(1, 1000);
                    $status = $roll <= 30 ? 'absent' : ($roll <= 70 ? 'half_day' : ($roll <= 170 ? 'late' : ($p['work_mode'] === 'remote' || ($p['work_mode'] === 'hybrid' && $roll % 3 === 0) ? 'remote' : 'present')));
                    if ($status !== 'absent') {
                        $lateBy = $status === 'late' ? mt_rand($grace + 5, $grace + 50) : max(0, mt_rand(-20, $grace));
                        $firstIn = $d->setTime($sh, $sm)->addMinutes($lateBy > 0 ? $lateBy : -mt_rand(0, 20));
                        $worked = $status === 'half_day' ? mt_rand(220, 260) : mt_rand(440, 560);
                        $lastOut = $firstIn->addMinutes($worked + 60);
                        $late = max(0, $lateBy - $grace);
                        $ot = max(0, $worked - 480);
                    }
                }
                $rows[] = [
                    'employee_id' => $p['employee_id'], 'work_date' => $date, 'shift_id' => $p['shift_id'], 'roster_id' => null, 'first_in_at' => $firstIn, 'last_out_at' => $lastOut,
                    'worked_minutes' => $worked, 'break_minutes' => $firstIn ? 60 : 0, 'late_by_minutes' => $late, 'early_by_minutes' => $early, 'overtime_minutes' => $ot,
                    'status' => $status, 'is_regularized' => false, 'regularized_by_user_id' => null, 'note' => null, 'created_at' => $d->addDay(), 'updated_at' => $d->addDay(),
                ];
                if ($firstIn) {
                    $src = (string) Rng::weighted(['web' => 60, 'mobile' => 30, 'kiosk' => 10]);
                    foreach ([['in', $firstIn], ['out', $lastOut]] as [$dir, $at]) {
                        $punches[] = ['employee_id' => $p['employee_id'], 'punch_at' => $at, 'direction' => $dir, 'source' => $src, 'lat' => null, 'lng' => null, 'ip' => '10.'.mt_rand(0, 40).'.'.mt_rand(0, 250).'.'.mt_rand(2, 250), 'user_agent' => 'Mozilla/5.0', 'device_id' => null, 'location_id' => $p['location_id'], 'is_out_of_range' => false, 'out_of_range_reason' => null, 'note' => null, 'created_by' => $p['user_id'], 'created_at' => $at, 'updated_at' => $at, 'kind' => 'work'];
                    }
                }
            }
        }
        $this->bulk->insert('attendance_days', $rows);
        $this->bulk->insert('attendance_punches', $punches);
    }

    // ---------------------------------------------------------------- expenses

    private function expenses(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-expenses');
        $tl = $this->tl();
        $end = $tl->end;
        $cats = DB::table('expense_categories')->get()->keyBy('slug');
        $ranges = ['travel' => [800, 12000], 'meals' => [300, 2500], 'accommodation' => [2500, 18000], 'office_supplies' => [200, 3500], 'software' => [500, 9000], 'training' => [1500, 25000]];
        $fx = self::FX[$this->run->bp['country'][3]] ?? 1.0;
        $currency = $this->run->bp['country'][3];
        $claims = $items = [];
        $n = 0;

        foreach ($this->working($tl->created, $end) as $p) {
            if ($p['level'] === 4 && $p['idx'] === 0 || ! Rng::chance(0.4)) {
                continue;
            }
            $from = $p['joining']->gt($tl->created) ? $p['joining'] : $tl->created;
            if ($from->diffInDays($end, true) < 10) {
                continue;
            }
            for ($k = 0, $count = mt_rand(1, 2); $k < $count; $k++) {
                $date = CarbonImmutable::instance(Rng::between($from->addDays(3), $end->subDay()))->startOfDay();
                $id = $this->bulk->id('expense_claims');
                $total = 0;
                $itemCount = mt_rand(1, 3);
                for ($i = 0; $i < $itemCount; $i++) {
                    $slug = array_rand($ranges);
                    $cat = $cats[$slug] ?? $cats->first();
                    $amt = round(mt_rand($ranges[$slug][0], $ranges[$slug][1]) * $fx / 10) * 10;
                    $total += $amt;
                    $items[] = ['claim_id' => $id, 'category_id' => $cat->id, 'description' => ucfirst(str_replace('_', ' ', $slug)).' — '.Rng::pick(['client visit', 'team offsite', 'monthly subscription', 'conference', 'office pantry']), 'amount' => $amt, 'spent_at' => $date->subDays(mt_rand(0, 6))->toDateString(), 'vendor' => Rng::pick(['Urban Cabs', 'Skyline Hotels', 'Cloudly', 'Office Mart', 'Learnly']), 'receipt_document_id' => null, 'is_billable' => false, 'notes' => null, 'created_at' => $date, 'updated_at' => $date];
                }
                $age = (int) $date->diffInDays($end, true);
                $status = $age < 5 ? (string) Rng::weighted(['draft' => 10, 'submitted' => 90]) : (string) Rng::weighted(['paid' => 50, 'approved' => 14, 'rejected' => 9, 'submitted' => 22, 'cancelled' => 5]);
                $submitted = $date->addHours(mt_rand(1, 20));
                $decided = in_array($status, ['approved', 'paid', 'rejected'], true) ? $submitted->addHours(mt_rand(6, 90)) : null;
                if ($decided && $decided->gt($end)) {
                    $decided = $end->subMinutes(30);
                }
                $approval = null;
                if ($status !== 'draft') {
                    $approvalStatus = match ($status) {
                        'submitted' => 'pending', 'approved', 'paid' => 'approved', default => $status
                    };
                    $approval = $this->approval('App\\Models\\Hrms\\Expense\\ExpenseClaim', $id, 'Expense claim', 'expense.decide', 'expense', $p, $approvalStatus, $submitted, ['claim_number' => sprintf('EXP-%d-%05d', $date->year, $n + 1)], $decided);
                }
                $approved = in_array($status, ['approved', 'paid'], true) ? $total : null;
                $claims[] = [
                    'id' => $id, 'employee_id' => $p['employee_id'], 'claim_number' => sprintf('EXP-%d-%05d', $date->year, ++$n), 'claim_date' => $date->toDateString(), 'period_year' => $date->year, 'period_month' => $date->month,
                    'purpose' => Rng::pick(['Client visit', 'Team offsite', 'Software licences', 'Training and certification', 'Office supplies', 'Business travel']), 'description' => null, 'currency' => $currency,
                    'total_amount' => $total, 'approved_amount' => $approved, 'reimbursed_amount' => $status === 'paid' ? $total : 0, 'status' => $status, 'approval_id' => $approval, 'paid_in_payroll_run_id' => null,
                    'paid_via' => $status === 'paid' ? 'manual' : null, 'decided_at' => $decided, 'decided_by_user_id' => $decided ? $this->managerUserId($p) : null, 'created_at' => $date, 'updated_at' => $decided ?? $submitted, 'deleted_at' => null,
                ];
            }
        }
        $this->flushApprovals();
        $this->bulk->insert('expense_claims', $claims);
        $this->bulk->insert('expense_claim_items', $items);
    }

    // ---------------------------------------------------------------- assets

    private function assets(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-assets');
        $tl = $this->tl();
        $end = $tl->end;
        $hr = $this->run->withRole('hr_manager')[0] ?? $this->run->people[0];
        $catIds = [];
        $catRows = [];
        foreach (NameBank::ASSET_CATALOGUE as $i => [$name, $code]) {
            $id = $this->bulk->id('asset_categories');
            $catIds[$code] = $id;
            $catRows[] = ['id' => $id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "$name issued to employees", 'default_condition' => 'good', 'is_active' => true, 'position' => $i + 1, 'is_system' => false, 'created_at' => $tl->created, 'updated_at' => $tl->created];
        }
        $this->bulk->insert('asset_categories', $catRows);

        $fx = self::FX[$this->run->bp['country'][3]] ?? 1.0;
        $assets = $assign = [];
        $serial = 0;
        $people = $this->working($tl->created, $end);
        foreach ($people as $p) {
            $kit = [['LAP', 1.0]];
            if (Rng::chance(0.5)) {
                $kit[] = ['MON', 1.0];
            }
            if ($p['level'] >= 3 && Rng::chance(0.5)) {
                $kit[] = ['MOB', 1.0];
            }
            if (Rng::chance(0.7)) {
                $kit[] = ['ACC', 1.0];
            }
            foreach ($kit as [$code]) {
                $cat = collect(NameBank::ASSET_CATALOGUE)->firstWhere(1, $code);
                [$brand, $model, $usd] = Rng::pick($cat[2]);
                $id = $this->bulk->id('assets');
                $purchased = $p['joining']->subDays(mt_rand(10, 120));
                $assignedAt = $p['joining']->gt($tl->created) ? $p['joining']->addDay() : CarbonImmutable::instance(Rng::between($tl->created, $end->subDays(2)));
                if ($purchased->gt($assignedAt)) {
                    $purchased = $assignedAt->subDays(mt_rand(3, 30));
                }
                $gone = in_array($p['status'], ['exited', 'terminated'], true);
                $returnedAt = $gone && $p['exit_date'] ? $p['exit_date'] : null;
                $assets[] = [
                    'id' => $id, 'asset_code' => sprintf('AST-%s-%04d', $code, ++$serial), 'name' => "$brand $model", 'category_id' => $catIds[$code], 'brand' => $brand, 'model' => $model,
                    'serial_number' => strtoupper(Str::random(10)), 'purchase_date' => $purchased->toDateString(), 'purchase_value' => round($usd * $fx), 'vendor' => 'TechSource Distribution',
                    'invoice_document_id' => null, 'warranty_ends_at' => $purchased->addYears(3)->toDateString(), 'condition' => $gone ? 'good' : Rng::pick(['new', 'good', 'good', 'fair']),
                    'status' => $gone ? 'available' : 'assigned', 'assigned_to_employee_id' => $gone ? null : $p['employee_id'], 'assigned_at' => $gone ? null : $assignedAt, 'returned_at' => $returnedAt,
                    'location_id' => $p['location_id'], 'notes' => null, 'created_by' => $hr['user_id'], 'created_at' => $purchased, 'updated_at' => $returnedAt ?? $assignedAt, 'deleted_at' => null,
                    'replaced_by_asset_id' => null, 'replacement_reason' => null, 'replaced_at' => null,
                ];
                $assign[] = [
                    'asset_id' => $id, 'employee_id' => $p['employee_id'], 'assigned_by_user_id' => $hr['user_id'], 'assigned_at' => $assignedAt, 'condition_out' => 'good',
                    'acknowledged_at' => $assignedAt->addHours(mt_rand(1, 48)), 'returned_at' => $returnedAt, 'condition_in' => $returnedAt ? 'good' : null,
                    'return_note' => $returnedAt ? 'Returned during exit clearance' : null, 'status' => $returnedAt ? 'returned' : 'active', 'created_at' => $assignedAt, 'updated_at' => $returnedAt ?? $assignedAt,
                ];
            }
        }
        // a small pool of unassigned stock
        for ($i = 0, $spare = max(3, (int) (count($people) * 0.06)); $i < $spare; $i++) {
            [$brand, $model, $usd] = Rng::pick(NameBank::ASSET_CATALOGUE[0][2]);
            $id = $this->bulk->id('assets');
            $purchased = $end->subDays(mt_rand(10, 200));
            $assets[] = [
                'id' => $id, 'asset_code' => sprintf('AST-LAP-%04d', ++$serial), 'name' => "$brand $model", 'category_id' => $catIds['LAP'], 'brand' => $brand, 'model' => $model, 'serial_number' => strtoupper(Str::random(10)),
                'purchase_date' => $purchased->toDateString(), 'purchase_value' => round($usd * $fx), 'vendor' => 'TechSource Distribution', 'invoice_document_id' => null, 'warranty_ends_at' => $purchased->addYears(3)->toDateString(),
                'condition' => 'new', 'status' => $i % 5 === 0 ? 'maintenance' : 'available', 'assigned_to_employee_id' => null, 'assigned_at' => null, 'returned_at' => null, 'location_id' => null, 'notes' => null,
                'created_by' => $hr['user_id'], 'created_at' => $purchased, 'updated_at' => $purchased, 'deleted_at' => null, 'replaced_by_asset_id' => null, 'replacement_reason' => null, 'replaced_at' => null,
            ];
        }
        $this->bulk->insert('assets', $assets);
        $this->bulk->insert('asset_assignments', $assign);
    }

    // ---------------------------------------------------------------- lifecycle

    private function onboarding(): void
    {
        $tl = $this->tl();
        $rows = [];
        foreach ($this->run->people as $p) {
            $age = (int) $p['joining']->diffInDays($tl->end, false);
            if ($age < 0 || $age > 75 || $p['joining']->lt($tl->created->subDays(30))) {
                continue;
            }
            $done = $age > 28;
            $rows[] = ['employee_id' => $p['employee_id'], 'template_id' => null, 'status' => $done ? 'completed' : 'in_progress', 'started_at' => $p['joining'], 'completed_at' => $done ? $p['joining']->addDays(mt_rand(10, 26)) : null, 'created_by' => $this->run->withRole('hr_manager')[0]['user_id'] ?? $this->run->people[0]['user_id'], 'created_at' => $p['joining'], 'updated_at' => $p['joining']->addDays(min($age, 14)), 'deleted_at' => null];
        }
        $this->bulk->insert('onboarding_cases', $rows);
    }

    private function offboarding(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-offboarding');
        $tl = $this->tl();
        $cases = $resign = [];
        $hr = $this->run->withRole('hr_manager')[0] ?? $this->run->people[0];
        foreach ($this->run->people as $p) {
            if (! in_array($p['status'], ['exited', 'terminated', 'on_notice'], true) || $p['exit_date'] === null) {
                continue;
            }
            $caseId = $this->bulk->id('offboarding_cases');
            $voluntary = $p['status'] !== 'terminated';
            $notice = (int) Rng::pick([30, 60, 90]);
            $received = $p['exit_date']->subDays($voluntary ? $notice : 7);
            if ($received->lt($p['joining'])) {
                $received = $p['joining']->addDays(30);
            }
            $done = $p['exit_date']->lte($tl->end);
            $cases[] = ['id' => $caseId, 'employee_id' => $p['employee_id'], 'last_working_day' => $p['exit_date']->toDateString(), 'reason' => $voluntary ? 'resigned' : 'terminated', 'status' => $done ? 'completed' : 'in_progress', 'notice_period_days' => $voluntary ? $notice : 0, 'exit_interview_at' => $done && $voluntary ? $p['exit_date']->subDays(2) : null, 'exit_interview_notes' => $done && $voluntary ? 'Left for a better opportunity; would recommend the company.' : null, 'resignation_received_at' => $received, 'created_by' => $hr['user_id'], 'created_at' => $received, 'updated_at' => $done ? $p['exit_date'] : $tl->end, 'deleted_at' => null];
            if ($voluntary) {
                $decided = $received->addDays(2);
                $rid = $this->bulk->id('resignation_requests');
                $approval = $this->approval('App\\Models\\Hrms\\Lifecycle\\ResignationRequest', $rid, 'Resignation', 'resignation.decide', 'offboarding', $p, 'approved', $received, ['last_day' => $p['exit_date']->toDateString()], $decided);
                $resign[] = ['id' => $rid, 'employee_id' => $p['employee_id'], 'requested_last_day' => $p['exit_date']->toDateString(), 'notice_period_days' => $notice, 'reason' => $p['exited_reason'] ?? 'Personal reasons', 'status' => 'approved', 'approval_id' => $approval, 'decided_at' => $decided, 'decided_by_user_id' => $this->managerUserId($p), 'decision_note' => 'Accepted. Handover plan agreed.', 'offboarding_case_id' => $caseId, 'created_at' => $received, 'updated_at' => $decided];
            }
        }
        $this->flushApprovals();
        $this->bulk->insert('offboarding_cases', $cases);
        $this->bulk->insert('resignation_requests', $resign);
    }

    // ---------------------------------------------------------------- compensation + payroll

    private function compensation(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-comp');
        $tl = $this->tl();
        $fx = self::FX[$this->run->bp['country'][3]] ?? 1.0;
        $currency = $this->run->bp['country'][3];
        $comps = DB::table('salary_components')->pluck('id', 'slug')->all();
        $structure = $this->bulk->id('salary_structures');
        $this->bulk->insert('salary_structures', [['id' => $structure, 'name' => 'Standard Grade', 'slug' => 'standard-grade-'.$this->run->bp['index'], 'currency' => $currency, 'effective_from' => $tl->created->toDateString(), 'description' => 'Default CTC breakup for all grades.', 'is_default' => true, 'is_active' => true, 'created_by' => $this->run->people[0]['user_id'], 'created_at' => $tl->created, 'updated_at' => $tl->created]]);
        $this->bulk->insert('salary_structure_components', [
            ['structure_id' => $structure, 'component_id' => $comps['basic-salary'], 'value' => 50, 'sequence' => 10, 'is_override' => false],
            ['structure_id' => $structure, 'component_id' => $comps['house-rent-allowance'], 'value' => 40, 'sequence' => 20, 'is_override' => false],
            ['structure_id' => $structure, 'component_id' => $comps['special-allowance'], 'value' => 60, 'sequence' => 30, 'is_override' => false],
            ['structure_id' => $structure, 'component_id' => $comps['provident-fund'], 'value' => 12, 'sequence' => 40, 'is_override' => false],
            ['structure_id' => $structure, 'component_id' => $comps['provident-fund-employee'], 'value' => 12, 'sequence' => 50, 'is_override' => false],
            ['structure_id' => $structure, 'component_id' => $comps['professional-tax'], 'value' => 200, 'sequence' => 60, 'is_override' => false],
        ]);

        $rows = [];
        foreach ($this->run->people as $p) {
            [$lo, $hi] = $p['idx'] === 0 ? [250000, 400000] : self::LEVEL_BANDS[$p['level']];
            $ctc = round(mt_rand($lo, $hi) * $fx / 1000) * 1000;
            $monthly = round($ctc / 12, 2);
            $gross = round($monthly * 0.92, 2);
            $id = $this->bulk->id('employee_salary_structures');
            $this->salaryRow[$p['employee_id']] = $id;
            $this->salary[$p['employee_id']] = ['gross' => $gross, 'ctc' => $ctc];
            $from = $p['joining']->gt($tl->created) ? $p['joining'] : $tl->created->startOfDay();
            $rows[] = ['id' => $id, 'employee_id' => $p['employee_id'], 'structure_id' => $structure, 'ctc_annual' => $ctc, 'monthly_ctc' => $monthly, 'gross_monthly' => $gross, 'effective_from' => $from->toDateString(), 'effective_to' => null, 'reason' => 'Initial CTC', 'is_current' => true, 'approved_by_user_id' => $this->run->people[0]['user_id'], 'created_at' => $from, 'updated_at' => $from];
        }
        $this->bulk->insert('employee_salary_structures', $rows);
    }

    private function payroll(): void
    {
        if ($this->salary === []) {
            $this->compensation();
        }
        Rng::seed($this->run->bp['index'], 'hr-payroll');
        $tl = $this->tl();
        $end = $tl->end;
        $initiator = ($this->run->withRole('payroll_manager')[0] ?? $this->run->people[0])['user_id'];
        $months = [];
        for ($i = 0; $i < (int) config('demo_seed.payroll_months') + 2 && count($months) < (int) config('demo_seed.payroll_months'); $i++) {
            $m = $end->startOfMonth()->subMonthsNoOverflow($i);
            $mEnd = $m->endOfMonth()->startOfDay();
            if ($mEnd->gte($end->startOfDay()) || $mEnd->lt($tl->created->startOfDay())) {
                continue; // only fully elapsed months, and only after the tenant existed
            }
            $months[] = [$m, $mEnd];
        }
        $months = array_reverse($months);

        $runs = $slips = [];
        foreach ($months as $k => [$m, $mEnd]) {
            $runId = $this->bulk->id('payroll_runs');
            $recent = $mEnd->diffInDays($end, true) < 7;
            $status = $recent ? 'review' : ($k === count($months) - 1 ? 'paid' : 'locked');
            $working = 0;
            for ($d = $m; $d->lte($mEnd); $d = $d->addDay()) {
                $working += $d->isWeekend() || isset($this->holidays[$d->toDateString()]) ? 0 : 1;
            }
            $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'employer' => 0.0];
            $count = 0;
            $slipStatus = $status === 'review' ? 'draft' : ($status === 'paid' ? 'paid' : 'published');
            foreach ($this->working($m, $mEnd) as $p) {
                if ($p['status'] === 'probation' && $p['joining']->gt($mEnd)) {
                    continue;
                }
                $sal = $this->salary[$p['employee_id']];
                $joinDay = $p['joining']->gt($m) ? $p['joining'] : $m;
                $exitDay = $p['exit_date'] && $p['exit_date']->lt($mEnd) ? $p['exit_date'] : $mEnd;
                $paidWorking = 0;
                for ($d = $joinDay; $d->lte($exitDay); $d = $d->addDay()) {
                    $paidWorking += $d->isWeekend() || isset($this->holidays[$d->toDateString()]) ? 0 : 1;
                }
                if ($paidWorking === 0) {
                    continue;
                }
                $ratio = $paidWorking / max(1, $working);
                $gross = round($sal['gross'] * $ratio, 2);
                $basic = round($gross * 0.5, 2);
                $hra = round($basic * 0.4, 2);
                $special = round($gross - $basic - $hra, 2);
                $pfEmp = round($basic * 0.12, 2);
                $tax = 200.0;
                $pfEr = round($basic * 0.12, 2);
                $deductions = round($pfEmp + $tax, 2);
                $net = round($gross - $deductions, 2);
                $totals['gross'] += $gross;
                $totals['deductions'] += $deductions;
                $totals['net'] += $net;
                $totals['employer'] += $pfEr;
                $count++;
                $slips[] = [
                    'payroll_run_id' => $runId, 'employee_id' => $p['employee_id'], 'employee_salary_structure_id' => $this->salaryRow[$p['employee_id']],
                    'earnings' => json_encode([['code' => 'basic-salary', 'name' => 'Basic Salary', 'amount' => $basic], ['code' => 'house-rent-allowance', 'name' => 'House Rent Allowance', 'amount' => $hra], ['code' => 'special-allowance', 'name' => 'Special Allowance', 'amount' => $special]]),
                    'deductions' => json_encode([['code' => 'provident-fund-employee', 'name' => 'Provident Fund (employee)', 'amount' => $pfEmp], ['code' => 'professional-tax', 'name' => 'Professional Tax', 'amount' => $tax]]),
                    'employer_contributions' => json_encode([['code' => 'provident-fund', 'name' => 'Provident Fund', 'amount' => $pfEr]]),
                    'statutory' => json_encode([]), 'gross_pay' => $gross, 'total_deductions' => $deductions, 'net_pay' => $net,
                    'working_days' => $working, 'paid_days' => $paidWorking, 'lop_days' => 0, 'ot_minutes' => 0, 'absent_days' => 0, 'leave_days' => 0,
                    'status' => $slipStatus, 'published_at' => $slipStatus === 'draft' ? null : $mEnd->addDay(), 'locked_at' => $status === 'locked' ? $mEnd->addDays(2) : null, 'created_at' => $mEnd, 'updated_at' => $mEnd->addDay(),
                ];
            }
            $runs[] = [
                'id' => $runId, 'period_year' => $m->year, 'period_month' => $m->month, 'pay_period_start' => $m->toDateString(), 'pay_period_end' => $mEnd->toDateString(), 'pay_date' => $mEnd->toDateString(),
                'status' => $status, 'employee_count' => $count, 'totals' => json_encode(array_map(fn ($v) => round($v, 2), $totals)), 'initiated_by_user_id' => $initiator,
                'approved_by_user_id' => $status === 'review' ? null : $this->run->people[0]['user_id'], 'approved_at' => $status === 'review' ? null : $mEnd->addDay(), 'processed_at' => $status === 'review' ? null : $mEnd->addDay(),
                'locked_at' => $status === 'locked' ? $mEnd->addDays(2) : null, 'notes' => null, 'created_at' => $mEnd, 'updated_at' => $mEnd->addDay(),
            ];
        }
        $this->bulk->insert('payroll_runs', $runs);
        $this->bulk->insert('payslips', $slips);
    }

    // ---------------------------------------------------------------- performance + talent

    private function performance(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-performance');
        $tl = $this->tl();
        $end = $tl->end;
        $owner = $this->run->people[0]['user_id'];
        $cycles = [];
        $year = $end->year;
        $window = $tl->days();
        $defs = [];
        if ($window > 200) {
            $defs[] = ['H2 '.($year - 1).' Review', CarbonImmutable::create($year - 1, 7, 1), CarbonImmutable::create($year - 1, 12, 31), 'completed', false];
        }
        $defs[] = ["H1 $year Goals", CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 6, 30), $window < 60 ? 'goal_setting' : 'check_in', true];
        foreach ($defs as $i => [$name, $s, $e, $stage, $active]) {
            if ($s->gt($end)) {
                continue;
            }
            $id = $this->bulk->id('performance_cycles');
            $cycles[$id] = ['id' => $id, 'name' => $name, 'slug' => Str::slug($name).'-'.$this->run->bp['index'], 'description' => "$name for all employees.", 'period_start' => $s->toDateString(), 'period_end' => $e->toDateString(), 'stage' => $stage, 'is_active' => $active, 'created_by' => $owner, 'created_at' => max($s->subDays(20), $tl->created), 'updated_at' => $end];
        }
        $this->bulk->insert('performance_cycles', array_values($cycles));

        $goals = $checks = [];
        foreach ($cycles as $c) {
            $cStart = CarbonImmutable::parse($c['period_start']);
            $cEnd = CarbonImmutable::parse($c['period_end']);
            $active = $c['is_active'];
            foreach ($this->working($cStart, min($cEnd, $end)) as $p) {
                if (in_array($p['status'], ['exited', 'terminated'], true) && $p['exit_date'] && $p['exit_date']->lt($cStart)) {
                    continue;
                }
                $created = max($cStart, $p['joining'], $tl->created)->addDays(mt_rand(1, 10));
                if ($created->gt($end)) {
                    continue;
                }
                for ($g = 0, $n = mt_rand(1, 3); $g < $n; $g++) {
                    $status = $active ? (string) Rng::weighted(['active' => 80, 'achieved' => 12, 'draft' => 8]) : (string) Rng::weighted(['achieved' => 65, 'missed' => 25, 'cancelled' => 10]);
                    $progress = match ($status) {
                        'achieved' => 100, 'missed' => mt_rand(20, 70), 'draft' => 0, 'cancelled' => mt_rand(0, 30), default => mt_rand(10, 85)
                    };
                    $goals[] = [
                        'cycle_id' => $c['id'], 'employee_id' => $p['employee_id'], 'title' => Rng::pick(['Reduce average resolution time', 'Ship the planned roadmap items', 'Improve customer satisfaction score', 'Upskill in a new technology', 'Mentor two junior colleagues', 'Cut operating costs', 'Raise test coverage', 'Complete compliance training']),
                        'description' => null, 'category' => Rng::pick(['delivery', 'quality', 'growth', 'leadership']), 'metric_type' => 'manual', 'target_value' => 100, 'weight' => round(100 / $n, 2), 'due_date' => $cEnd->toDateString(), 'status' => $status,
                        'progress_percent' => $progress, 'progress_source' => 'manual', 'progress_evidence' => null, 'achieved_at' => $status === 'achieved' ? min($cEnd, $end)->subDays(mt_rand(1, 20)) : null, 'created_by' => $p['user_id'], 'created_at' => $created, 'updated_at' => min($cEnd, $end),
                    ];
                }
                if ($active && Rng::chance(0.5)) {
                    $at = CarbonImmutable::instance(Rng::between($created, $end));
                    $checks[] = ['cycle_id' => $c['id'], 'employee_id' => $p['employee_id'], 'body' => Rng::pick(['Good progress on my goals this month.', 'Delivery is on track; waiting on one dependency.', 'Learned a lot from the recent project.', 'Capacity is tight but manageable.']), 'mood' => Rng::pick(['great', 'good', 'good', 'ok', 'low']), 'blockers' => Rng::chance(0.25) ? 'Waiting on access to the staging environment.' : null, 'needs_support' => Rng::chance(0.15), 'created_at' => $at];
                }
            }
        }
        $this->bulk->insert('performance_goals', $goals);
        $this->bulk->insert('check_ins', $checks);
    }

    private function talent(): void
    {
        Rng::seed($this->run->bp['index'], 'hr-talent');
        $tl = $this->tl();
        $skillIds = [];
        $rows = [];
        foreach (NameBank::SKILLS as [$name, $category]) {
            $id = $this->bulk->id('skills');
            $skillIds[] = $id;
            $rows[] = ['id' => $id, 'name' => $name, 'category' => $category, 'description' => null, 'is_active' => true, 'created_at' => $tl->created, 'updated_at' => $tl->created];
        }
        $this->bulk->insert('skills', $rows);
        $assess = [];
        foreach ($this->run->people as $p) {
            foreach (array_unique(Rng::sample($skillIds, mt_rand(2, 4))) as $sid) {
                $manager = $this->managerUserId($p);
                $at = CarbonImmutable::instance(Rng::between(max($tl->created, $p['joining']), $tl->end));
                $assess[] = ['employee_id' => $p['employee_id'], 'skill_id' => $sid, 'level' => mt_rand(1, 5), 'source' => Rng::chance(0.5) ? 'manager' : 'self', 'assessed_by_user_id' => $manager, 'assessed_at' => $at, 'note' => null, 'created_at' => $at, 'updated_at' => $at];
            }
        }
        $this->bulk->insert('employee_skills', $assess);
    }
}
