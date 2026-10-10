{{ $line }}

{{ __('notifications.labels.project') }}: {{ $task['project_name'] ?? 'Your project' }}
{{ __('notifications.labels.task') }}: {{ $task['key'] ?? 'Task' }} — {{ $task['title'] ?? 'a task' }}
@if (! empty($task['snippet']))
{{ __('notifications.labels.comment') }}: {{ $task['snippet'] }}
@endif
@if (! empty($task['from_status']) || ! empty($task['to_status']))
{{ __('notifications.labels.status') }}: {{ $task['from_status'] }} → {{ $task['to_status'] }}
@endif
@if (! empty($task['blocked_by']))
{{ __('notifications.labels.unblocked_by') }}: {{ $task['blocked_by']['title'] }}
@endif

{{ __('notifications.labels.open_task') }}: {!! $url !!}
