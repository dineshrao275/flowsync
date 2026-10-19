@props(['href' => null, 'variant' => 'primary', 'size' => 'md', 'type' => 'button'])

@php
    $sizes = [
        'sm' => 'px-3.5 py-2 text-sm',
        'md' => 'px-5 py-2.5 text-sm',
        'lg' => 'px-7 py-3.5 text-base',
    ];

    $variants = [
        'primary' => 'bg-blue-600 text-white shadow-lg shadow-blue-600/25 hover:bg-blue-500 focus-visible:outline-blue-600',
        'dark' => 'bg-ink text-white shadow-lg shadow-slate-900/20 ring-1 ring-white/10 hover:bg-slate-700 focus-visible:outline-slate-900',
        'light' => 'bg-white text-slate-900 shadow-lg shadow-blue-950/20 hover:bg-blue-50 focus-visible:outline-white',
        'ghost' => 'bg-transparent text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-blue-600',
        'ghost-light' => 'bg-transparent text-white ring-1 ring-inset ring-white/30 hover:bg-white/10 focus-visible:outline-white',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 '
        .($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
