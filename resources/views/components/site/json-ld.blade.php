{{-- Structured data for the public site. Organization + WebSite ship on
     every page; BreadcrumbList on every non-home page via $crumbs
     ([['name' => …, 'url' => …], …], last item may omit url); FAQPage
     ships when $faq carries [['q' => …, 'a' => …], …] pairs. --}}
@props(['crumbs' => [], 'faq' => [], 'article' => null])

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
            'inLanguage' => app()->getLocale(),
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

    if (is_array($article) && ! empty($article['headline'])) {
        $posting = ['@type' => 'BlogPosting', 'headline' => $article['headline']];
        foreach (['description' => 'description', 'image' => 'image', 'datePublished' => 'datePublished', 'dateModified' => 'dateModified'] as $key => $prop) {
            if (! empty($article[$key])) {
                $posting[$prop] = $article[$key];
            }
        }
        $posting['author'] = ['@type' => $article['author'] ?? null ? 'Person' : 'Organization', 'name' => $article['author'] ?? $siteName];
        $posting['inLanguage'] = app()->getLocale();
        $graph[] = $posting;
    }

    $faq = array_values(array_filter($faq, fn ($entry) => is_array($entry) && trim((string) ($entry['q'] ?? '')) !== '' && trim((string) ($entry['a'] ?? '')) !== ''));
    if ($faq !== []) {
        $graph[] = [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($entry) => [
                '@type' => 'Question',
                'name' => $entry['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $entry['a']],
            ], $faq),
        ];
    }
@endphp

<script type="application/ld+json">@json(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
