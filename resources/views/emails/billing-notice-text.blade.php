{{ $greeting }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionUrl)
{!! $actionUrl !!}
@endif
