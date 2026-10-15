<?php

namespace App\Http\Controllers\Hrms\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Holiday\HolidayCalendarRequest;
use App\Http\Requests\Hrms\Holiday\HolidayRequest;
use App\Http\Requests\Hrms\Holiday\SeedYearRequest;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Services\Hrms\Holiday\HolidayCalendarService;
use App\Services\Hrms\Holiday\HolidayPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Holiday/HRMS — calendars, their holidays, and year seeding over HTTP.
 *
 * Thin: validate, authorize, delegate, present. Nested holiday creation
 * authorizes against the parent calendar's update — managing the calendar
 * covers adding to it, and a reader cannot smuggle rows into a catalogue
 * they may not edit. The seed-year run takes `hrms.holidays.manage` at
 * the route.
 */
class HolidayCalendarController extends Controller
{
    public function __construct(
        private readonly HolidayCalendarService $calendars,
        private readonly HolidayPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', HolidayCalendar::class);

        return response()->json([
            'calendars' => $this->calendars->calendars()
                ->map(fn (HolidayCalendar $calendar): array => $this->presenter->calendar($calendar))
                ->all(),
        ]);
    }

    public function store(HolidayCalendarRequest $request): JsonResponse
    {
        $this->authorize('create', HolidayCalendar::class);

        $calendar = $this->calendars->createCalendar($request->validated(), $request->user());

        return response()->json([
            'message' => 'Holiday calendar created.',
            'calendar' => $this->presenter->calendar($calendar),
        ], Response::HTTP_CREATED);
    }

    public function update(HolidayCalendarRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('update', $calendar);

        $updated = $this->calendars->updateCalendar($calendar, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Holiday calendar updated.',
            'calendar' => $this->presenter->calendar($updated),
        ]);
    }

    public function destroy(Request $request, HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('delete', $calendar);

        $this->calendars->deleteCalendar($calendar, $request->user());

        return response()->json(['message' => 'Holiday calendar deleted.']);
    }

    public function holidays(HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('view', $calendar);

        return response()->json([
            'calendar' => $this->presenter->calendar($calendar),
            'holidays' => $calendar->holidays()
                ->orderBy('date')
                ->get()
                ->map(fn (Holiday $holiday): array => $this->presenter->holiday($holiday))
                ->all(),
        ]);
    }

    public function storeHoliday(HolidayRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $this->authorize('update', $calendar);

        $holiday = $this->calendars->createHoliday($calendar, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Holiday created.',
            'holiday' => $this->presenter->holiday($holiday),
        ], Response::HTTP_CREATED);
    }

    public function seedYear(SeedYearRequest $request): JsonResponse
    {
        $result = $this->calendars->seedYear((int) $request->validated()['year'], $request->user());

        return response()->json([
            'message' => "Year seeded: {$result['calendars']} calendars, {$result['holidays']} holidays.",
            ...$result,
        ], Response::HTTP_CREATED);
    }
}
