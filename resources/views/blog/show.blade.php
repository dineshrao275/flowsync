@extends('layouts.site', [
    'title' => $post->seo_title ?: $post->title,
    'seoTitle' => $post->seo_title ?: $post->title,
    'metaDescription' => $post->excerpt ?: $post->meta_description,
    'ogImage' => $ogImage ?? null,
    'canonicalOverride' => $canonicalOverride ?? null,
    'forceRobots' => $forceRobots ?? ($post->noindex ? 'noindex, follow' : 'index, follow'),
    'article' => $article ?? null,
])

@section('content')
    <article class="mx-auto max-w-3xl px-4 pb-16 pt-12 sm:px-6 sm:pt-16">
        <header class="text-center">
            <p class="flex flex-wrap items-center justify-center gap-2 text-xs text-slate-500">
                @if($post->category)<a href="/blog/category/{{ $post->category->slug }}" class="rounded-full bg-blue-50 px-2.5 py-0.5 font-semibold text-blue-700">{{ $post->category->name }}</a>@endif
                @if($post->published_at)<time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('M j, Y') }}</time>@endif
                @if($post->reading_minutes)<span aria-hidden="true">·</span><span>{{ __('site.blog.minRead', ['count' => $post->reading_minutes]) }}</span>@endif
                @if($post->author_name)<span aria-hidden="true">·</span><span>{{ __('site.blog.by', ['name' => $post->author_name]) }}</span>@endif
            </p>
            <h1 class="mx-auto mt-4 max-w-2xl text-4xl font-extrabold tracking-tight text-balance text-slate-900 sm:text-5xl">{{ $post->title }}</h1>
            @if($post->excerpt)<p class="mx-auto mt-4 max-w-xl text-lg text-slate-600">{{ $post->excerpt }}</p>@endif
        </header>

        @if($post->cover)
            <x-site.img :media="$post->cover" :eager="true" class="mt-8 w-full rounded-3xl shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5" />
        @endif

        <div class="mt-8 space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-10 [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:tracking-tight [&_h2]:text-slate-900 [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-slate-900 [&_p]:leading-relaxed [&_p]:text-slate-600 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-6 [&_ul]:text-slate-600 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-6 [&_ol]:text-slate-600 [&_a]:font-medium [&_a]:text-blue-600 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-blue-200 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-slate-600">
            {!! $post->body !!}
        </div>

        @if(($related ?? collect())->isNotEmpty())
            <section class="mt-14" aria-label="{{ __('site.blog.relatedLabel') }}">
                <h2 class="mb-6 text-center text-2xl font-extrabold tracking-tight text-slate-900">{{ __('site.blog.keepReading') }}</h2>
                <div class="grid gap-5 sm:grid-cols-3">
                    @foreach ($related as $item)
                        <a href="/blog/{{ $item->slug }}" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                            <p class="text-sm font-bold text-slate-900">{{ $item->title }}</p>
                            @if($item->published_at)<p class="mt-1 text-xs text-slate-500">{{ $item->published_at->format('M j, Y') }}</p>@endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="mt-14 text-center">
            <x-site.button href="/app/register" size="lg">{{ __('site.actions.startFree') }}</x-site.button>
        </div>
    </article>
@endsection
