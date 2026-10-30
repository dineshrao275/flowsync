{{ __('Your scheduled report ":name" is attached (:rows rows).', ['name' => $reportName, 'rows' => $rows]) }}
@if ($truncated)

{{ __('It was cut at the first :max rows; narrow the filters to see the rest.', ['max' => \App\Services\Reports\ReportRunner::MAX_ROWS]) }}
@endif
