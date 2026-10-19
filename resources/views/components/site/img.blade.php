@props(['media' => null, 'src' => null, 'alt' => null, 'eager' => false])

@php
    $src = $src ?? $media?->url();
    $alt = $alt ?? $media?->alt ?? $media?->original_name ?? '';
    $width = $media?->width;
    $height = $media?->height;
@endphp

@if($src)
    <img
        src="{{ $src }}"
        alt="{{ $alt }}"
        {{-- Above-the-fold images (LCP) load eagerly with high priority; the rest lazy. --}}
        loading="{{ $eager ? 'eager' : 'lazy' }}"
        @if($eager)fetchpriority="high" @endif
        decoding="async"
        @if($width)width="{{ $width }}" @endif
        @if($height)height="{{ $height }}" @endif
        {{ $attributes->merge(['class' => 'h-auto max-w-full']) }}
    >
@endif
