<?php

namespace App\Http\Controllers\Hrms\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Holiday\HolidayImportRequest;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Services\Hrms\Holiday\HolidayImporter;
use App\Support\Hrms\CsvTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Holiday/HRMS — import a calendar's holidays from CSV or ICS (preview →
 * commit). Importing into a calendar is editing it, so both calls authorize
 * `update` on the calendar.
 */
class HolidayImportController extends Controller
{
    public function __construct(private readonly HolidayImporter $importer) {}

    public function sample(): StreamedResponse
    {
        $this->authorize('create', HolidayCalendar::class);

        return response()->streamDownload(
            fn () => print (CsvTable::render(HolidayImporter::SAMPLE)),
            'holiday-import-sample.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function preview(HolidayImportRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('update', $calendar);

        return response()->json($this->importer->evaluate($calendar, $this->importer->parse($request->file('file'))));
    }

    public function import(HolidayImportRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('update', $calendar);

        $evaluated = $this->importer->evaluate($calendar, $this->importer->parse($request->file('file')));
        $result = $this->importer->commit($calendar, $evaluated['rows'], $request->boolean('skip_invalid'), $request->user());

        return response()->json(['message' => "{$result['created']} holiday(s) imported.", ...$result], Response::HTTP_CREATED);
    }
}
