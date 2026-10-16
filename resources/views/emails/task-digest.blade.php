{{ __('notifications.digest.intro') }}

@foreach ($items as $item)
- {{ $item['line'] }}
  {!! $item['url'] !!}
@endforeach
