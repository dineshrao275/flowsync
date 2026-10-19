<?php

namespace App\Http\Controllers;

use App\Models\SavedReport;
use App\Services\Reports\ReportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The report builder (F11.9). A saved report belongs to its creator and always runs with the creator's own row scope. */
class ReportBuilderController extends Controller
{
    public function __construct(private readonly ReportRunner $runner) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'catalog' => $this->runner->catalog(),
            'reports' => SavedReport::query()->where('user_id', $request->user()->id)->orderBy('name')->get(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $out = $this->runner->run($request->user(), $d['dataset'], $d['columns'], $d['filters'] ?? [], 200);

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request, true);
        $this->runner->run($request->user(), $d['dataset'], $d['columns'], $d['filters'] ?? [], 1); // reject a bad definition now

        $report = SavedReport::create($d + ['user_id' => $request->user()->id]);

        return response()->json(['message' => __('Report saved.'), 'report' => $report], 201);
    }

    public function destroy(Request $request, SavedReport $report): JsonResponse
    {
        abort_unless($report->user_id === $request->user()->id, 404);
        $report->delete();

        return response()->json(['message' => __('Report deleted.')]);
    }

    public function export(Request $request, SavedReport $report): StreamedResponse
    {
        abort_unless($report->user_id === $request->user()->id, 404);
        $out = $this->runner->run($request->user(), $report->dataset, $report->columns, $report->filters ?? []);

        return response()->streamDownload(fn () => print (self::csv($out['columns'], $out['rows'])), str($report->name)->slug().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, string> $columns @param list<array<string, mixed>> $rows */
    public static function csv(array $columns, array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_values($columns));
        foreach ($rows as $row) {
            // A cell opening with a formula character is neutralised so a spreadsheet cannot run it.
            fputcsv($h, array_map(fn ($k) => is_string($v = $row[$k] ?? '') && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, array_keys($columns)));
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $saving = false): array
    {
        return $request->validate([
            'name' => [$saving ? 'required' : 'nullable', 'string', 'max:160'],
            'dataset' => ['required', Rule::in(array_keys($this->runner->catalog()))],
            'columns' => ['required', 'array', 'min:1', 'max:20'],
            'columns.*' => ['string', 'max:40'],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['nullable'],
            'schedule' => [$saving ? 'required' : 'nullable', Rule::in(['none', 'daily', 'weekly'])],
        ]);
    }
}
