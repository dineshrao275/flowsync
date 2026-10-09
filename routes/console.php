<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Platform: usage + backups. withoutOverlapping() so a sweep that outlives
// its minute is skipped, never started a second time by the next tick.
Schedule::command('tenants:collect-usage')->daily()->withoutOverlapping();
Schedule::command('tenants:backup --all --verify')->dailyAt('02:00')->withoutOverlapping();
// Expired full-tenant export ZIPs (signed link lives 1h) are deleted, rows kept as history.
Schedule::command('tenants:prune-exports')->dailyAt('03:00')->withoutOverlapping();
// Time-based automation triggers (overdue / due soon) — only for projects with a rule listening.
Schedule::command('automation:time-triggers --all')->hourly()->withoutOverlapping();
// Processed domain events and settled webhook deliveries are kept 30 days.
Schedule::command('events:prune --all')->dailyAt('03:45')->withoutOverlapping();
// Sign-up drafts that never finished (no database exists for them) are removed after a week.
Schedule::command('tenants:prune-drafts')->dailyAt('03:30')->withoutOverlapping();
// Impersonation is time-boxed; close the logs of sessions nobody touched again.
Schedule::command('tenants:close-impersonations')->everyTenMinutes()->withoutOverlapping();

// Platform: trials end at `trial_ends_at` exactly — no grace period (yet).
Schedule::command('tenants:expire-trials')->dailyAt('00:05')->withoutOverlapping();

// HRMS fleet sweeps. Every command here takes exactly one of --tenant=ID or
// --all and is idempotent, so the worst a re-run does is nothing. Cadences
// follow each command's own docblock ("nightly", "daily", "monthly over the
// previous month"); times are staggered so one fleet pass never stacks on
// another, and `hrms:attendance-rollup` runs late so the day's punches have
// landed (late punches on a closed date are repaired on demand via
// `--date=` or the attendance UI backfill). NOT scheduled, by design:
// `hrms:backfill-employees` (data migration) and `hrms:statutory-recompute`
// (names a specific run with --run) — both stay operator commands.
Schedule::command('hrms:surveys-open-close --all')->dailyAt('00:15')->withoutOverlapping();
Schedule::command('hrms:documents-expiry --all')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('hrms:comp-off-accrue --all')->monthlyOn(1, '04:45')->withoutOverlapping();
Schedule::command('hrms:leave-rollover --all')->dailyAt('04:50')->withoutOverlapping();
// Retention's bare run reports only; `--apply` (the deleting run) stays manual.
Schedule::command('hrms:retention --all')->weeklyOn(0, '05:00')->withoutOverlapping();
Schedule::command('hrms:onboarding-reminders --all')->dailyAt('08:30')->withoutOverlapping();
Schedule::command('hrms:assets-overdue --all')->dailyAt('10:30')->withoutOverlapping();
Schedule::command('hrms:report-digests --all')->dailyAt('18:00')->withoutOverlapping();
Schedule::command('hrms:performance-evidence --all')->dailyAt('21:30')->withoutOverlapping();
Schedule::command('hrms:attendance-rollup --all')->dailyAt('23:45')->withoutOverlapping();
// Derivation writes only where the tenant opted in
// (attendance.auto_derive_from_work_logs), so --apply on the schedule is safe.
Schedule::command('hrms:derive-attendance --all --apply')->dailyAt('23:55')->withoutOverlapping();
