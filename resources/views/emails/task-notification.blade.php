{{ $line }}

Project: {{ $task['project_name'] ?? 'Your project' }}
Task: {{ $task['key'] ?? 'Task' }} — {{ $task['title'] ?? 'a task' }}
@if (! empty($task['snippet']))
Comment: {{ $task['snippet'] }}
@endif
@if (! empty($task['from_status']) || ! empty($task['to_status']))
Status: {{ $task['from_status'] }} → {{ $task['to_status'] }}
@endif
@if (! empty($task['blocked_by']))
Unblocked by: {{ $task['blocked_by']['title'] }}
@endif

Open task: {!! $url !!}