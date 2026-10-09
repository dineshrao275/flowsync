<?php

namespace Tests\Feature;

use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Shared\HrmsAuditLog;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.16 — holidays from CSV or ICS into a calendar: preview classifies each
 * row (new / duplicate / invalid) without writing, the commit writes the new
 * ones with audit rows, and the endpoints are manage-only.
 */
class HrmsHolidayImportTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private HolidayCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.holidays']);
        $this->loginAdmin();
        $this->calendar = HolidayCalendar::create(['name' => 'Import target', 'slug' => 'import-target']);
    }

    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_csv_preview_classifies_rows_and_writes_nothing(): void
    {
        $this->calendar->holidays()->create(['name' => 'Existing Day', 'date' => '2027-02-01', 'type' => 'public']);

        $body = $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import/preview", ['file' => $this->file('h.csv', implode("\n", [
            'name,date,type,is_recurring',
            'Fresh Day,2027-03-01,optional,yes',
            'existing day,2027-02-01,public,no',
            'Fresh Day,2027-03-01,public,no',
            'Broken,2027-02-30,public,no',
            'Odd,2027-04-01,sabbatical,no',
        ]))])->assertOk()->json();

        $this->assertSame(1, $body['new']);
        $this->assertSame(2, $body['duplicates']);
        $this->assertSame(2, $body['invalid']);
        $this->assertSame(1, $this->calendar->holidays()->count());
    }

    public function test_csv_commit_creates_new_rows_only_when_invalid_ones_are_skipped(): void
    {
        $csv = implode("\n", ['name,date', 'Alpha,2027-05-01', 'Beta,not-a-date']);

        $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import", ['file' => $this->file('h.csv', $csv)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import", ['file' => $this->file('h.csv', $csv), 'skip_invalid' => true])
            ->assertCreated()->assertJsonPath('created', 1);

        $this->assertSame(['Alpha'], $this->calendar->holidays()->pluck('name')->all());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.created')->exists());
    }

    public function test_ics_events_import_with_multi_day_expansion_and_recurrence(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0',
            'BEGIN:VEVENT', 'SUMMARY:Winter\\, Break', 'DTSTART;VALUE=DATE:20271224', 'DTEND;VALUE=DATE:20271227', 'END:VEVENT',
            'BEGIN:VEVENT', 'SUMMARY:Independence Day', 'DTSTART;VALUE=DATE:20270815', 'RRULE:FREQ=YEARLY', 'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import", ['file' => $this->file('cal.ics', $ics)])
            ->assertCreated()->assertJsonPath('created', 4);

        $this->assertSame(
            ['2027-08-15', '2027-12-24', '2027-12-25', '2027-12-26'],
            $this->calendar->holidays()->orderBy('date')->get()->map(fn (Holiday $h) => $h->date->toDateString())->all(),
        );
        $this->assertTrue($this->calendar->holidays()->where('name', 'Independence Day')->value('is_recurring') == true);
        $this->assertSame(3, $this->calendar->holidays()->where('name', 'Winter, Break')->count());
    }

    public function test_an_empty_calendar_file_and_a_reader_are_refused(): void
    {
        $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import/preview", ['file' => $this->file('x.ics', "BEGIN:VCALENDAR\r\nEND:VCALENDAR")])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actAs($this->userWith(['hrms.view', 'hrms.holidays.view']));
        $this->postJson("/api/hrms/holidays/calendars/{$this->calendar->id}/import", ['file' => $this->file('h.csv', "name,date\nA,2027-01-01")])
            ->assertForbidden();
    }
}
