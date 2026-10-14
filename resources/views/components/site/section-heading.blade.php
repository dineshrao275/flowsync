@props(['eyebrow' => null, 'heading' => null, 'lead' => null, 'align' => 'center', 'dark' => false])

@php
    $alignClass = $align === 'center' ? 'text-center mx-auto items-center' : 'text-left items-start';
@endphp

<div {{ $attributes->merge(['class' => 'flex max-w-2xl flex-col gap-4 '.$alignClass]) }}>
    @if($eyebrow)
        <div><x-site.eyebrow :dark="$dark">{{ $eyebrow }}</x-site.eyebrow></div>
    @endif
    @if($heading)
        <h2 class="text-3xl font-extrabold tracking-tight text-balance sm:text-4xl {{ $dark ? 'text-white' : 'text-slate-900' }}">{{ $heading }}</h2>
    @endif
    @if($lead)
        <p class="text-lg text-pretty {{ $dark ? 'text-indigo-100/80' : 'text-slate-600' }}">{{ $lead }}</p>
    @endif
</div>
