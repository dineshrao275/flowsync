@props(['dark' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-widest '.($dark ? 'bg-white/10 text-blue-200 ring-1 ring-inset ring-white/15' : 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-100')]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dark ? 'bg-blue-300' : 'bg-blue-500' }}" aria-hidden="true"></span>{{ $slot }}
</span>
