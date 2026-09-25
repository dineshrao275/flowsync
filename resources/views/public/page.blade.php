@extends('layouts.site', [
    'title' => $page->seo_title ?: $page->title,
    'seoTitle' => $page->seo_title ?: $page->title,
    'metaDescription' => $page->meta_description,
    'ogImage' => $page->og_image,
])

@section('content')
    @foreach ($page->content ?? [] as $block)
        @switch($block['type'] ?? 'text')
            @case('hero')
                <section class="hero block">
                    <h1>{{ $block['heading'] ?? $page->title }}</h1>
                    @if(!empty($block['subtext']))
                        <p class="lead">{{ $block['subtext'] }}</p>
                    @endif
                    @if(!empty($block['cta']['label']))
                        <a class="btn" href="{{ $block['cta']['href'] ?? '/app/register' }}">{{ $block['cta']['label'] }}</a>
                    @endif
                </section>
                @break

            @case('features')
                <section class="block">
                    <div class="features">
                        @foreach ($block['items'] ?? [] as $item)
                            <div class="item">
                                <h3>{{ $item['title'] ?? '' }}</h3>
                                <p>{{ $item['text'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('cta')
                <section class="cta-band block">
                    <h2>{{ $block['heading'] ?? 'Ready to start?' }}</h2>
                    @if(!empty($block['text']))
                        <p>{{ $block['text'] }}</p>
                    @endif
                    @if(!empty($block['cta']['label']))
                        <a class="btn" href="{{ $block['cta']['href'] ?? '/app/register' }}">{{ $block['cta']['label'] }}</a>
                    @endif
                </section>
                @break

            @default
                <section class="text-block block">
                    @if(!empty($block['heading']))
                        <h2>{{ $block['heading'] }}</h2>
                    @endif
                    @foreach (preg_split('/\R\R/', $block['body'] ?? '') as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </section>
        @endswitch
    @endforeach
@endsection