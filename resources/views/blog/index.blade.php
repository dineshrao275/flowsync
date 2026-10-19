@extends('layouts.site', [
    'title' => $title ?? __('site.blog.title'),
    'seoTitle' => $seoTitle ?? null,
    'metaDescription' => $metaDescription ?? null,
])

@section('content')
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-gradient-to-b from-blue-50 via-white to-white"></div>
            <div class="absolute -top-32 left-1/2 h-96 w-[52rem] -translate-x-1/2 rounded-full bg-blue-200/40 blur-3xl"></div>
        </div>
        <div class="mx-auto max-w-6xl px-4 pb-10 pt-14 text-center sm:px-6 sm:pt-20">
            @if(!empty($category))
                <x-site.eyebrow>{{ __('site.blog.category') }}</x-site.eyebrow>
            @endif
            <h1 class="mx-auto mt-4 max-w-2xl text-4xl font-extrabold tracking-tight text-balance text-slate-900 sm:text-5xl">{{ $title ?? __('site.blog.title') }}</h1>
            @if(!empty($category?->description))
                <p class="mx-auto mt-4 max-w-xl text-lg text-slate-600">{{ $category->description }}</p>
            @elseif(empty($category))
                <p class="mx-auto mt-4 max-w-xl text-lg text-slate-600">{{ __('site.blog.tagline') }}</p>
            @endif
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
        @if($posts->isEmpty())
            <p class="py-10 text-center text-slate-500">{{ __('site.blog.empty') }}</p>
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-blue-600/5">
                        <a href="/blog/{{ $post->slug }}" class="block" tabindex="-1" aria-hidden="true">
                            @if($post->cover)
                                <x-site.img :media="$post->cover" class="aspect-[16/9] w-full object-cover" />
                            @else
                                <div class="flex aspect-[16/9] w-full items-center justify-center bg-gradient-to-br from-blue-100 via-violet-50 to-white text-2xl font-extrabold text-blue-300" aria-hidden="true">{{ mb_substr($post->title, 0, 1) }}</div>
                            @endif
                        </a>
                        <div class="flex flex-1 flex-col p-5">
                            <p class="mb-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                @if($post->category)<a href="/blog/category/{{ $post->category->slug }}" class="rounded-full bg-blue-50 px-2.5 py-0.5 font-semibold text-blue-700">{{ $post->category->name }}</a>@endif
                                @if($post->published_at)<time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('M j, Y') }}</time>@endif
                                @if($post->reading_minutes)<span aria-hidden="true">·</span><span>{{ __('site.blog.minRead', ['count' => $post->reading_minutes]) }}</span>@endif
                            </p>
                            <h2 class="text-lg font-extrabold tracking-tight text-slate-900">
                                <a href="/blog/{{ $post->slug }}" class="transition group-hover:text-blue-700">{{ $post->title }}</a>
                            </h2>
                            @if($post->excerpt)<p class="mt-1.5 flex-1 text-sm leading-relaxed text-slate-600">{{ $post->excerpt }}</p>@endif
                        </div>
                    </article>
                @endforeach
            </div>

            @if($posts->hasPages())
                <nav class="mt-10 flex items-center justify-center gap-2" aria-label="{{ __('site.blog.pages') }}">
                    @if($posts->onFirstPage())
                        <span class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-300 ring-1 ring-inset ring-slate-200">{{ __('site.blog.newer') }}</span>
                    @else
                        <a href="{{ $posts->previousPageUrl() }}" rel="prev" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50">{{ __('site.blog.newer') }}</a>
                    @endif
                    <span class="px-2 text-sm text-slate-500">{{ __('site.blog.pageOf', ['current' => $posts->currentPage(), 'last' => $posts->lastPage()]) }}</span>
                    @if($posts->hasMorePages())
                        <a href="{{ $posts->nextPageUrl() }}" rel="next" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50">{{ __('site.blog.older') }}</a>
                    @else
                        <span class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-300 ring-1 ring-inset ring-slate-200">{{ __('site.blog.older') }}</span>
                    @endif
                </nav>
            @endif
        @endif
    </section>
@endsection
