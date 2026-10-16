{{-- SEO head tags for the public marketing site. All values have safe
     defaults so error pages render without controller data. Per-page
     overrides (canonical override, noindex, og overrides) land with the
     C2 page-SEO tab; this partial already honours them when present. --}}
@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'robots' => 'index, follow',
    'ogTitle' => null,
    'ogDescription' => null,
    'ogUrl' => null,
    'ogType' => 'website',
    'ogImage' => null,
    'twitterCard' => null,
])

@php
    $siteName = $siteName ?? config('app.name', 'FlowSync');
    $title = $title ?? $siteName;
    $canonical = $canonical ?? url()->current();
    $ogTitle = $ogTitle ?? $title;
    $ogDescription = $ogDescription ?? $description ?? '';
    $ogUrl = $ogUrl ?? $canonical;
    $twitterCard = $twitterCard ?? ($ogImage ? 'summary_large_image' : 'summary');
    $ogLocale = str_replace('-', '_', config('locales.supported.'.app()->getLocale().'.intl', 'en-US'));
@endphp

<title>{{ $title }}</title>
@if($description)<meta name="description" content="{{ $description }}">@endif
<link rel="canonical" href="{{ $canonical }}">
<meta name="robots" content="{{ $robots }}">
<meta name="theme-color" content="#4f46eb">
<link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">

<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ $ogLocale }}">
<meta property="og:title" content="{{ $ogTitle }}">
@if($ogDescription)<meta property="og:description" content="{{ $ogDescription }}">@endif
<meta property="og:url" content="{{ $ogUrl }}">
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:locale" content="en_US">
@if($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endif

<meta name="twitter:card" content="{{ $twitterCard }}">
<meta name="twitter:title" content="{{ $ogTitle }}">
@if($ogDescription)<meta name="twitter:description" content="{{ $ogDescription }}">@endif
@if($ogImage)<meta name="twitter:image" content="{{ $ogImage }}">@endif
