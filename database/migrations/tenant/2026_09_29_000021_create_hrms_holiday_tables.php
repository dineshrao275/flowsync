<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS holiday tables (tenant database).
 *
 *   holiday_calendars             — named calendars, optionally per
 *       country/region. `is_default` marks the tenant fallback every
 *       employee reads unless assigned otherwise.
 *   holidays                      — concrete dates in a calendar. Recurring
 *       rows store the current year's occurrence in `date`; the month/day
 *       is what recurs, expanded per year by the service (P8.2).
 *   employee_holiday_calendars    — who follows which calendar, when. A
 *       link row with its own validity window, so no timestamps — the
 *       window is the record, not its bookkeeping.
 *   holiday_optional_holidays     — one employee's answer to a restricted
 *       holiday: taken (on a date) or skipped. Unique per pair, so the
 *       answer is single-valued by construction.
 *
 * `country` is nullable: seeded calendars always name one, but a tenant's
 * hand-made "Office" calendar need not belong to a jurisdiction. P8.2's
 * seeder fills it from config either way.
 *
 * FK choices: calendars own their holidays and assignments cascade with
 * both ends (a deleted calendar's rows, an exited employee's links); the
 * optional answers cascade with their holiday and employee for the same
 * reason.
 *
 * **No `tenant_id` column** — the tenant database *is* the boundary (Phase 13).
 *
 * Repair-safe: every table is created only when absent, because
 * `tenants:provision` re-runs tenant migrations to repair a database that
 * failed partway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createHolidayCalendars();
        $this->createHolidays();
        $this->createEmployeeHolidayCalendars();
        $this->createHolidayOptionalHolidays();
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_optional_holidays');
        Schema::dropIfExists('employee_holiday_calendars');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_calendars');
    }

    private function createHolidayCalendars(): void
    {
        if (Schema::hasTable('holiday_calendars')) {
            return;
        }

        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // ISO-3166 alpha-2 when the calendar belongs to a jurisdiction;
            // null for a tenant's hand-made generic calendar.
            $table->string('country', 2)->nullable();
            $table->string('region')->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
        });
    }

    private function createHolidays(): void
    {
        if (Schema::hasTable('holidays')) {
            return;
        }

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('holiday_calendars')->cascadeOnDelete();
            $table->string('name');
            $table->date('date');

            // public = nobody works; restricted = working day unless taken
            // as optional; optional = taken only when declared.
            $table->enum('type', ['public', 'restricted', 'optional'])->default('public');
            $table->boolean('is_recurring')->default(false);
            $table->text('description')->nullable();

            $table->timestamps();

            // "This calendar's holidays in a window", the only read path.
            $table->index(['calendar_id', 'date']);
        });
    }

    private function createEmployeeHolidayCalendars(): void
    {
        if (Schema::hasTable('employee_holiday_calendars')) {
            return;
        }

        Schema::create('employee_holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained('holiday_calendars')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unique(['employee_id', 'calendar_id', 'effective_from']);
        });
    }

    private function createHolidayOptionalHolidays(): void
    {
        if (Schema::hasTable('holiday_optional_holidays')) {
            return;
        }

        Schema::create('holiday_optional_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('holiday_id')->constrained('holidays')->cascadeOnDelete();

            $table->enum('status', ['taken', 'skipped'])->default('skipped');
            $table->date('taken_date')->nullable();
            $table->text('note')->nullable();

            $table->unique(['employee_id', 'holiday_id']);
        });
    }
};
