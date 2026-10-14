@extends('layouts.site', [
    'title' => $page->seo_title ?: $page->title,
    'seoTitle' => $page->seo_title ?: $page->title,
    'metaDescription' => $page->meta_description,
    'ogImage' => $page->og_image,
    'canonicalOverride' => $canonicalOverride ?? null,
    'forceRobots' => $forceRobots ?? null,
    'ogTitleOverride' => $ogTitleOverride ?? null,
    'ogDescriptionOverride' => $ogDescriptionOverride ?? null,
])

@section('content')
    @php($mediaMap = $mediaMap ?? [])
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

            @case('richtext')
                <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                    <div class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-10 [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:tracking-tight [&_h2]:text-slate-900 [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-slate-900 [&_p]:leading-relaxed [&_p]:text-slate-600 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-6 [&_ul]:text-slate-600 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-6 [&_ol]:text-slate-600 [&_a]:font-medium [&_a]:text-indigo-600 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-indigo-200 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-slate-600">
                        {!! $block['html'] ?? '' !!}
                    </div>
                </section>
                @break

            @case('logo_cloud')
                <section class="mx-auto max-w-6xl px-4 py-12 text-center sm:px-6">
                    @if(!empty($block['heading']))
                        <p class="mb-7 text-xs font-semibold uppercase tracking-widest text-slate-400">{{ $block['heading'] }}</p>
                    @endif
                    <div class="flex flex-wrap items-center justify-center gap-x-10 gap-y-5">
                        @foreach ($block['logos'] ?? [] as $logo)
                            @php($logoMedia = !empty($logo['media_id']) ? ($mediaMap[(int) $logo['media_id']] ?? null) : null)
                            @if($logoMedia)
                                <x-site.img :media="$logoMedia" class="h-8 w-auto opacity-60 grayscale transition hover:opacity-100 hover:grayscale-0" />
                            @elseif(!empty($logo['name']))
                                <span class="text-lg font-extrabold tracking-tight text-slate-400 transition hover:text-slate-600">{{ $logo['name'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </section>
                @break

            @case('stats')
                @php($statCols = match (min(4, max(1, count($block['items'] ?? [])))) { 1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', default => 'lg:grid-cols-4' })
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="grid gap-px overflow-hidden rounded-3xl bg-slate-200/70 ring-1 ring-slate-200/70 sm:grid-cols-2 {{ $statCols }}">
                        @foreach ($block['items'] ?? [] as $stat)
                            <div class="bg-white px-8 py-10 text-center">
                                <p class="text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">{{ $stat['value'] ?? '' }}</p>
                                <p class="mt-2 text-sm font-medium text-slate-500">{{ $stat['label'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('steps')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? null" :lead="$block['lead'] ?? null" align="center" class="mb-10" />
                    <ol class="grid gap-5 md:grid-cols-3">
                        @foreach ($block['items'] ?? [] as $i => $step)
                            <li class="relative rounded-2xl border border-slate-200/80 bg-white p-6 pt-7 shadow-sm">
                                <span class="absolute -top-4 left-6 flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-extrabold text-white shadow-lg shadow-indigo-600/30" aria-hidden="true">{{ $i + 1 }}</span>
                                <h3 class="mb-1.5 text-base font-bold tracking-tight text-slate-900">{{ $step['title'] ?? '' }}</h3>
                                <p class="text-sm leading-relaxed text-slate-600">{{ $step['text'] ?? '' }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>
                @break

            @case('split')
                @php($splitMedia = !empty($block['media_id']) ? ($mediaMap[(int) $block['media_id']] ?? null) : null)
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="grid items-center gap-10 lg:grid-cols-2">
                        <div class="{{ !empty($block['flip']) ? 'lg:order-2' : '' }}">
                            @if($splitMedia)
                                <x-site.img :media="$splitMedia" class="w-full rounded-3xl shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5" />
                            @else
                                <div class="flex aspect-[4/3] w-full items-center justify-center rounded-3xl bg-gradient-to-br from-indigo-100 via-violet-50 to-white text-sm font-medium text-indigo-300 ring-1 ring-slate-900/5">Add an image in the CMS</div>
                            @endif
                        </div>
                        <div class="{{ !empty($block['flip']) ? 'lg:order-1' : '' }}">
                            @if(!empty($block['eyebrow']))<div class="mb-3"><x-site.eyebrow>{{ $block['eyebrow'] }}</x-site.eyebrow></div>@endif
                            @if(!empty($block['heading']))<h2 class="text-3xl font-extrabold tracking-tight text-balance text-slate-900 sm:text-4xl">{{ $block['heading'] }}</h2>@endif
                            @if(!empty($block['body']))<p class="mt-4 text-lg leading-relaxed text-slate-600">{{ $block['body'] }}</p>@endif
                            @if(!empty($block['cta']['label']))
                                <div class="mt-7"><x-site.button href="{{ $block['cta']['href'] ?? '/app/register' }}">{{ $block['cta']['label'] }}</x-site.button></div>
                            @endif
                        </div>
                    </div>
                </section>
                @break

            @case('testimonials')
                <section class="bg-slate-50/70 py-14">
                    <div class="mx-auto max-w-6xl px-4 sm:px-6">
                        <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? null" :lead="$block['lead'] ?? null" align="center" class="mb-10" />
                        <div class="grid gap-5 md:grid-cols-3">
                            @foreach ($block['items'] ?? [] as $quote)
                                @php($avatar = !empty($quote['media_id']) ? ($mediaMap[(int) $quote['media_id']] ?? null) : null)
                                <figure class="flex flex-col rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                                    <blockquote class="flex-1 text-[15px] leading-relaxed text-slate-700">“{{ $quote['quote'] ?? '' }}”</blockquote>
                                    <figcaption class="mt-5 flex items-center gap-3 border-t border-slate-100 pt-4">
                                        @if($avatar)
                                            <x-site.img :media="$avatar" class="h-10 w-10 rounded-full object-cover" />
                                        @else
                                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 text-sm font-extrabold text-indigo-700" aria-hidden="true">{{ mb_substr($quote['name'] ?? '?', 0, 1) }}</span>
                                        @endif
                                        <span>
                                            <span class="block text-sm font-bold text-slate-900">{{ $quote['name'] ?? '' }}</span>
                                            @if(!empty($quote['role']))<span class="block text-xs text-slate-500">{{ $quote['role'] }}</span>@endif
                                        </span>
                                    </figcaption>
                                </figure>
                            @endforeach
                        </div>
                    </div>
                </section>
                @break

            @case('team')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? null" :lead="$block['lead'] ?? null" align="center" class="mb-10" />
                    <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($block['members'] ?? [] as $member)
                            @php($photo = !empty($member['media_id']) ? ($mediaMap[(int) $member['media_id']] ?? null) : null)
                            <div class="text-center">
                                @if($photo)
                                    <x-site.img :media="$photo" class="mx-auto aspect-square w-full max-w-44 rounded-2xl object-cover" />
                                @else
                                    <span class="mx-auto flex aspect-square w-full max-w-44 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-100 to-violet-100 text-3xl font-extrabold text-indigo-400" aria-hidden="true">{{ mb_substr($member['name'] ?? '?', 0, 1) }}</span>
                                @endif
                                <p class="mt-3 text-sm font-bold text-slate-900">{{ $member['name'] ?? '' }}</p>
                                @if(!empty($member['role']))<p class="text-xs text-slate-500">{{ $member['role'] }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('video')
                @php($embed = \App\Support\SiteMedia::videoEmbedUrl($block['embed_url'] ?? null))
                <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6">
                    @if(!empty($block['heading']))
                        <h2 class="mb-6 text-center text-3xl font-extrabold tracking-tight text-slate-900">{{ $block['heading'] }}</h2>
                    @endif
                    @if($embed)
                        <div class="overflow-hidden rounded-3xl shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5">
                            <iframe src="{{ $embed }}" title="{{ $block['heading'] ?? 'Video' }}" class="aspect-video w-full" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        </div>
                    @elseif(!empty($block['embed_url']))
                        <div class="text-center"><x-site.button href="{{ $block['embed_url'] }}" variant="ghost">Watch the video</x-site.button></div>
                    @endif
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
