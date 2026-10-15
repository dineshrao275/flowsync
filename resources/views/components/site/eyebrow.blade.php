@props(['dark' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-widest '.($dark ? 'bg-white/10 text-indigo-200 ring-1 ring-inset ring-white/15' : 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-100')]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dark ? 'bg-indigo-300' : 'bg-indigo-500' }}" aria-hidden="true"></span>{{ $slot }}
</span>
