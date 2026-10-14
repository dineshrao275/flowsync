{{-- Structured data for the public site. Organization + WebSite ship on
     every page; BreadcrumbList on every non-home page via $crumbs
     ([['name' => …, 'url' => …], …], last item may omit url). --}}
@props(['crumbs' => []])

@php
    $siteName = $siteName ?? config('app.name', 'FlowSync');
    $base = url('/');
    $graph = [
        [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => $base,
            'logo' => url('/favicon.svg'),
        ],
        [
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => $base,
            'inLanguage' => 'en',
        ],
    ];

    if (! empty($crumbs)) {
        $items = [];
        foreach (array_values($crumbs) as $i => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name']];
            if (! empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
@endphp

<script type="application/ld+json">@json(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
