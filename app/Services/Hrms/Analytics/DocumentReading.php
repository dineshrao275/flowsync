<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;

/**
 * Analytics/HRMS — who holds what the law asks for, and what lapses when.
 *
 * Compliance is holders over headcount per mandatory type; expiry counts
 * run at thirty, sixty and ninety days, with the rows behind them only
 * on request — names travel with the detailed call, never the counts.
 */
class DocumentReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = [], bool $detailed = true): array
    {
        return $this->remember('documents', $filters + ['detailed' => $detailed], function () use ($detailed): array {
            $employees = Employee::query()->active()->count();

            $compliance = DocumentType::query()->where('is_mandatory', true)->orderBy('name')->get()
                ->map(function (DocumentType $type) use ($employees): array {
                    $holders = EmployeeDocument::query()->where('document_type_id', $type->id)
                        ->distinct()->count('employee_id');

                    return [
                        'type' => $type->name,
                        'holders' => $holders,
                        'percent' => $employees === 0 ? 0.0 : round($holders / $employees * 100, 1),
                    ];
                })->all();

            $expiring = [];

            foreach ([30, 60, 90] as $days) {
                $query = EmployeeDocument::query()->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<=', today()->addDays($days)->toDateString());

                $expiring["{$days}d"] = [
                    'count' => (clone $query)->count(),
                    'items' => $detailed ? (clone $query)->with('employee:id,employee_code,name')
                        ->orderBy('expires_at')->limit(20)->get()
                        ->map(fn (EmployeeDocument $document): array => [
                            'id' => $document->id,
                            'title' => $document->title,
                            'employee' => $document->employee?->displayName(),
                            'expires_at' => $document->expires_at->toDateString(),
                        ])->all() : [],
                ];
            }

            return ['compliance' => $compliance, 'expiring' => $expiring];
        });
    }
}
