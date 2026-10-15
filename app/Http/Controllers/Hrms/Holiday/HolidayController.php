<?php

namespace App\Http\Controllers\Hrms\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Holiday\HolidayRequest;
use App\Models\Hrms\Holiday\Holiday;
use App\Services\Hrms\Holiday\HolidayCalendarService;
use App\Services\Hrms\Holiday\HolidayPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Holiday/HRMS — individual holidays over HTTP.
 *
 * Update and delete answer per row through the holiday policy; creation
 * lives nested under its calendar, where the parent's update gate decides.
 */
class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayCalendarService $calendars,
        private readonly HolidayPresenter $presenter,
    ) {}

    public function update(HolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('update', $holiday);

        $updated = $this->calendars->updateHoliday($holiday, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Holiday updated.',
            'holiday' => $this->presenter->holiday($updated),
        ]);
    }

    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $this->calendars->deleteHoliday($holiday, $request->user());

        return response()->json(['message' => 'Holiday deleted.']);
    }
}
