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
                <section class="relative overflow-hidden">
                    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                        <div class="absolute inset-0 bg-gradient-to-b from-indigo-50 via-white to-white"></div>
                        <div class="absolute -top-32 left-1/2 h-96 w-[52rem] -translate-x-1/2 rounded-full bg-indigo-200/40 blur-3xl"></div>
                        <div class="absolute -top-16 right-[8%] h-64 w-64 rounded-full bg-violet-200/40 blur-3xl"></div>
                    </div>
                    <div class="mx-auto max-w-6xl px-4 pb-16 pt-16 text-center sm:px-6 sm:pt-24">
                        <h1 class="mx-auto max-w-3xl animate-fade-up text-4xl font-extrabold tracking-tight text-balance text-slate-900 sm:text-5xl lg:text-6xl">{{ $block['heading'] ?? $page->title }}</h1>
                        @if(!empty($block['subtext']))
                            <p class="mx-auto mt-5 max-w-2xl animate-fade-up text-lg text-pretty text-slate-600 [animation-delay:120ms]">{{ $block['subtext'] }}</p>
                        @endif
                        @if(!empty($block['cta']['label']))
                            <div class="mt-8 flex animate-fade-up flex-col items-center justify-center gap-3 [animation-delay:240ms] sm:flex-row">
                                <x-site.button href="{{ $block['cta']['href'] ?? '/app/register' }}" size="lg">{{ $block['cta']['label'] }}</x-site.button>
                                <x-site.button href="/app/login" variant="ghost" size="lg">Log in</x-site.button>
                            </div>
                        @endif
                    </div>
                </section>
                @break

            @case('features')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($block['items'] ?? [] as $item)
                            <div class="group rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-600/5">
                                <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-base font-extrabold text-white shadow-lg shadow-indigo-600/25" aria-hidden="true">{{ mb_substr($item['title'] ?? '?', 0, 1) }}</div>
                                <h3 class="mb-1.5 text-base font-bold tracking-tight text-slate-900">{{ $item['title'] ?? '' }}</h3>
                                <p class="text-sm leading-relaxed text-slate-600">{{ $item['text'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('cta')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="relative overflow-hidden rounded-3xl bg-slate-950 px-6 py-14 text-center sm:px-12 sm:py-16">
                        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                            <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-600/30 blur-3xl"></div>
                            <div class="absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-violet-600/30 blur-3xl"></div>
                        </div>
                        <div class="relative">
                            <x-site.section-heading :heading="$block['heading'] ?? 'Ready to start?'" :lead="$block['text'] ?? null" dark align="center" class="mb-8" />
                            @if(!empty($block['cta']['label']))
                                <x-site.button href="{{ $block['cta']['href'] ?? '/app/register' }}" variant="light" size="lg">{{ $block['cta']['label'] }}</x-site.button>
                            @endif
                        </div>
                    </div>
                </section>
                @break

            @default
                <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-10">
                        @if(!empty($block['heading']))
                            <h2 class="mb-4 text-2xl font-extrabold tracking-tight text-slate-900">{{ $block['heading'] }}</h2>
                        @endif
                        <div class="space-y-4 leading-relaxed text-slate-600">
                            @foreach (preg_split('/\R\R/', $block['body'] ?? '') as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    </div>
                </section>
        @endswitch
    @endforeach
@endsection
