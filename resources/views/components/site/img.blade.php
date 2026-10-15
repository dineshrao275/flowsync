@props(['media' => null, 'src' => null, 'alt' => null])

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
        loading="lazy"
        decoding="async"
        @if($width)width="{{ $width }}" @endif
        @if($height)height="{{ $height }}" @endif
        {{ $attributes->merge(['class' => 'h-auto max-w-full']) }}
    >
@endif
