<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\OneOnOneRequest;
use App\Models\Hrms\Performance\OneOnOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Performance/HRMS — 1:1 conversations over HTTP.
 *
 * Flat routes (1:1s stand outside cycles), participant-gated: either side
 * of the room or manage to file and edit. Notes and follow-ups patch
 * through update — a conversation write-up grows after the meeting, which
 * is why check-ins (dated history) have no update endpoint and these do.
 */
class OneOnOneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OneOnOne::class);

        $query = OneOnOne::query()
            ->with(['employee:id,employee_code,name', 'manager:id,employee_code,name'])
            ->orderByDesc('scheduled_at');

        if ($request->has('employee_id')) {
            $id = (int) $request->query('employee_id');
            $query->where(fn ($nested) => $nested
                ->where('employee_id', $id)
                ->orWhere('manager_employee_id', $id));
        }

        return response()->json([
            'one_on_ones' => $query->get()->map(fn (OneOnOne $row): array => $this->present($row))->all(),
        ]);
    }

    public function show(OneOnOne $oneOnOne): JsonResponse
    {
        $this->authorize('view', $oneOnOne);

        return response()->json(['one_on_one' => $this->present($oneOnOne)]);
    }

    public function store(OneOnOneRequest $request): JsonResponse
    {
        $this->authorize('create', OneOnOne::class);

        $oneOnOne = OneOnOne::create([...$request->validated(), 'created_by' => $request->user()->id]);

        return response()->json([
            'message' => 'One-on-one scheduled.',
            'one_on_one' => $this->present($oneOnOne->refresh()),
        ], Response::HTTP_CREATED);
    }

    public function update(OneOnOneRequest $request, OneOnOne $oneOnOne): JsonResponse
    {
        $this->authorize('update', $oneOnOne);

        $oneOnOne->update($request->validated());

        return response()->json([
            'message' => 'One-on-one updated.',
            'one_on_one' => $this->present($oneOnOne->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OneOnOne $oneOnOne): array
    {
        $oneOnOne->loadMissing(['employee:id,employee_code,name', 'manager:id,employee_code,name']);

        $person = fn ($record): ?array => $record === null ? null : [
            'id' => $record->id,
            'employee_code' => $record->employee_code,
            'name' => $record->displayName(),
        ];

        return [
            'id' => $oneOnOne->id,
            'employee_id' => $oneOnOne->employee_id,
            'employee' => $person($oneOnOne->employee),
            'manager_employee_id' => $oneOnOne->manager_employee_id,
            'manager' => $person($oneOnOne->manager),
            'scheduled_at' => $oneOnOne->scheduled_at?->toIso8601String(),
            'duration_minutes' => $oneOnOne->duration_minutes,
            'agenda' => $oneOnOne->agenda,
            'notes' => $oneOnOne->notes,
            'follow_up' => $oneOnOne->follow_up,
            'action_items' => $oneOnOne->action_items ?? [],
            'status' => $oneOnOne->status->value,
        ];
    }
}
