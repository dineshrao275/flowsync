@extends('layouts.site', [
    'title' => $page->seo_title ?: $page->title,
    'seoTitle' => $page->seo_title ?: $page->title,
    'metaDescription' => $page->meta_description,
    'ogImage' => $page->og_image,
    'canonicalOverride' => $canonicalOverride ?? null,
    'forceRobots' => $forceRobots ?? null,
    'ogTitleOverride' => $ogTitleOverride ?? null,
    'ogDescriptionOverride' => $ogDescriptionOverride ?? null,
    'faqSchema' => $faqSchema ?? [],
])

@section('content')
    @php($mediaMap = $mediaMap ?? [])
    @foreach ($page->content ?? [] as $block)
        @switch($block['type'] ?? 'text')
            @case('hero')
                <section class="relative overflow-hidden">
                    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                        <div class="absolute inset-0 bg-gradient-to-b from-blue-50 via-white to-white"></div>
                        <div class="absolute -top-32 left-1/2 h-96 w-[52rem] -translate-x-1/2 rounded-full bg-blue-200/40 blur-3xl"></div>
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
                                <x-site.button href="/app/login" variant="ghost" size="lg">{{ __('site.actions.logIn') }}</x-site.button>
                            </div>
                        @endif
                        @php($heroMedia = !empty($block['media_id']) ? ($mediaMap[(int) $block['media_id']] ?? null) : null)
                        @if($heroMedia)
                            <div class="relative mx-auto mt-14 max-w-5xl animate-fade-up [animation-delay:360ms]">
                                <div class="pointer-events-none absolute -inset-x-6 -bottom-6 top-10 -z-10 rounded-[2rem] bg-gradient-to-b from-blue-500/20 to-violet-500/20 blur-2xl" aria-hidden="true"></div>
                                <x-site.browser-frame :media="$heroMedia" :eager="true" />
                            </div>
                        @endif
                    </div>
                </section>
                @break

            @case('showcase')
                <section class="py-16" data-showcase>
                    <div class="mx-auto max-w-6xl px-4 sm:px-6">
                        <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? null" :lead="$block['lead'] ?? null" align="center" class="mb-10" />
                        @php($tour = collect($block['items'] ?? [])->filter(fn ($it) => !empty($it['media_id']) && isset($mediaMap[(int) $it['media_id']]))->values())
                        @if($tour->isNotEmpty())
                            <div role="tablist" aria-label="{{ $block['heading'] ?? __('site.showcase.label') }}" class="mb-8 flex flex-wrap justify-center gap-2">
                                @foreach($tour as $n => $item)
                                    <button type="button" role="tab" id="tour-tab-{{ $loop->parent->index }}-{{ $n }}" aria-controls="tour-panel-{{ $loop->parent->index }}-{{ $n }}" aria-selected="{{ $n === 0 ? 'true' : 'false' }}" data-showcase-tab="{{ $n }}"
                                        class="rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 aria-selected:bg-blue-600 aria-selected:text-white aria-selected:ring-blue-600 aria-selected:shadow-lg aria-selected:shadow-blue-600/25">{{ $item['title'] ?? '' }}</button>
                                @endforeach
                            </div>
                            @foreach($tour as $n => $item)
                                <div role="tabpanel" id="tour-panel-{{ $loop->parent->index }}-{{ $n }}" aria-labelledby="tour-tab-{{ $loop->parent->index }}-{{ $n }}" data-showcase-panel="{{ $n }}" class="mb-12 data-[ready]:mb-0">
                                    <div class="mx-auto max-w-5xl">
                                        <x-site.browser-frame :media="$mediaMap[(int) $item['media_id']]" :eager="$n === 0" />
                                        @if(!empty($item['text']))<p class="mx-auto mt-6 max-w-2xl text-center text-lg text-pretty text-slate-600">{{ $item['text'] }}</p>@endif
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </section>
                @break

            @case('features')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($block['items'] ?? [] as $item)
                            <div class="group rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-600/5">
                                <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-600 text-base font-extrabold text-white shadow-lg shadow-blue-600/25" aria-hidden="true">{{ mb_substr($item['title'] ?? '?', 0, 1) }}</div>
                                <h3 class="mb-1.5 text-base font-bold tracking-tight text-slate-900">{{ $item['title'] ?? '' }}</h3>
                                <p class="text-sm leading-relaxed text-slate-600">{{ $item['text'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('cta')
                <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <div class="relative overflow-hidden rounded-3xl scale-fixed bg-ink px-6 py-14 text-center sm:px-12 sm:py-16">
                        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                            <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-blue-600/30 blur-3xl"></div>
                            <div class="absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-violet-600/30 blur-3xl"></div>
                        </div>
                        <div class="relative">
                            <x-site.section-heading :heading="$block['heading'] ?? __('site.blocks.readyToStart')" :lead="$block['text'] ?? null" dark align="center" class="mb-8" />
                            @if(!empty($block['cta']['label']))
                                <x-site.button href="{{ $block['cta']['href'] ?? '/app/register' }}" variant="light" size="lg">{{ $block['cta']['label'] }}</x-site.button>
                            @endif
                        </div>
                    </div>
                </section>
                @break

            @case('richtext')
                <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                    <div class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-10 [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:tracking-tight [&_h2]:text-slate-900 [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-slate-900 [&_p]:leading-relaxed [&_p]:text-slate-600 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-6 [&_ul]:text-slate-600 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-6 [&_ol]:text-slate-600 [&_a]:font-medium [&_a]:text-blue-600 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-blue-200 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-slate-600">
                        {!! $block['html'] ?? '' !!}
                    </div>
                </section>
                @break

            @case('logo_cloud')
                <section class="mx-auto max-w-6xl px-4 py-12 text-center sm:px-6">
                    @if(!empty($block['heading']))
                        <p class="mb-7 text-xs font-semibold uppercase tracking-widest text-slate-500">{{ $block['heading'] }}</p>
                    @endif
                    <div class="flex flex-wrap items-center justify-center gap-x-10 gap-y-5">
                        @foreach ($block['logos'] ?? [] as $logo)
                            @php($logoMedia = !empty($logo['media_id']) ? ($mediaMap[(int) $logo['media_id']] ?? null) : null)
                            @if($logoMedia)
                                <x-site.img :media="$logoMedia" class="h-8 w-auto opacity-60 grayscale transition hover:opacity-100 hover:grayscale-0" />
                            @elseif(!empty($logo['name']))
                                <span class="text-lg font-extrabold tracking-tight text-slate-500 transition hover:text-slate-700">{{ $logo['name'] }}</span>
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
                                <span class="absolute -top-4 left-6 flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-extrabold text-white shadow-lg shadow-blue-600/30" aria-hidden="true">{{ $i + 1 }}</span>
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
                                <x-site.img :media="$splitMedia" :eager="$loop->first" class="w-full rounded-3xl shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5" />
                            @else
                                <div class="relative aspect-[4/3] w-full overflow-hidden rounded-3xl scale-fixed bg-ink shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5" aria-hidden="true">
                                    <div class="absolute -left-16 -top-16 h-64 w-64 rounded-full bg-blue-600/40 blur-3xl"></div>
                                    <div class="absolute -bottom-20 -right-16 h-72 w-72 rounded-full bg-violet-600/40 blur-3xl"></div>
                                    <div class="absolute inset-6 rounded-2xl border border-white/10 bg-white/5"></div>
                                    <div class="absolute inset-10 space-y-3">
                                        <div class="h-3 w-2/3 rounded-full bg-white/20"></div>
                                        <div class="h-3 w-1/2 rounded-full bg-white/10"></div>
                                        <div class="grid grid-cols-3 gap-3 pt-2">
                                            <div class="h-16 rounded-xl bg-white/10"></div>
                                            <div class="h-16 rounded-xl bg-blue-500/30"></div>
                                            <div class="h-16 rounded-xl bg-white/10"></div>
                                        </div>
                                    </div>
                                </div>
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
                                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-sm font-extrabold text-blue-700" aria-hidden="true">{{ mb_substr($quote['name'] ?? '?', 0, 1) }}</span>
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
                                    <span class="mx-auto flex aspect-square w-full max-w-44 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-100 to-violet-100 text-3xl font-extrabold text-blue-400" aria-hidden="true">{{ mb_substr($member['name'] ?? '?', 0, 1) }}</span>
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
                            <iframe src="{{ $embed }}" title="{{ $block['heading'] ?? __('site.video.title') }}" class="aspect-video w-full" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        </div>
                    @elseif(!empty($block['embed_url']))
                        <div class="text-center"><x-site.button href="{{ $block['embed_url'] }}" variant="ghost">{{ __('site.video.watch') }}</x-site.button></div>
                    @endif
                </section>
                @break

            @case('pricing')
                @php($plans = \App\Models\SubscriptionPlan::where('is_active', true)->when(!empty($block['product']), fn ($q) => $q->where('product', $block['product']))->orderBy('sort_order')->orderBy('id')->get())
                @php($planCols = match (min(3, max(1, $plans->count()))) { 1 => 'lg:grid-cols-1 lg:max-w-xl lg:mx-auto', 2 => 'lg:grid-cols-2 lg:max-w-4xl lg:mx-auto', default => 'lg:grid-cols-3' })
                @php($moduleMeta = config('subscriptions.module_meta', []))
                @if($plans->isNotEmpty())
                    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                        <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? __('site.pricing.heading')" :lead="$block['lead'] ?? null" align="center" class="mb-10" />
                        <div class="grid items-stretch gap-5 {{ $planCols }}">
                            @foreach ($plans as $plan)
                                @php($modules = array_values(array_filter((array) ($plan->limits['modules'] ?? []))))
                                <div class="relative flex flex-col rounded-3xl p-7 {{ $plan->is_default ? 'scale-fixed bg-ink text-white shadow-2xl shadow-slate-900/20 ring-2 ring-blue-500' : 'border border-slate-200/80 bg-white shadow-sm' }}">
                                    @if($plan->is_default)
                                        <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white shadow-lg shadow-blue-600/30">{{ __('site.pricing.mostPopular') }}</span>
                                    @endif
                                    <h3 class="text-lg font-extrabold tracking-tight {{ $plan->is_default ? 'text-white' : 'text-slate-900' }}">{{ $plan->name }}</h3>
                                    @if($plan->description)<p class="mt-1 text-sm {{ $plan->is_default ? 'text-blue-100/70' : 'text-slate-500' }}">{{ $plan->description }}</p>@endif
                                    <p class="mt-5 flex items-baseline gap-1.5">
                                        <span class="text-4xl font-extrabold tracking-tight {{ $plan->is_default ? 'text-white' : 'text-slate-900' }}">{{ $plan->price_cents > 0 ? number_format($plan->price_cents / 100, 0).' '.strtoupper($plan->currency ?? 'USD') : __('site.pricing.free') }}</span>
                                        @if($plan->price_cents > 0)<span class="text-sm {{ $plan->is_default ? 'text-blue-100/70' : 'text-slate-500' }}">{{ __('site.pricing.perMonth') }}{{ $plan->billing_cycle === 'annual' ? __('site.pricing.billedAnnually') : '' }}</span>@endif
                                    </p>
                                    @if($plan->trial_duration_days)<p class="mt-1 text-xs font-medium {{ $plan->is_default ? 'text-blue-200' : 'text-blue-600' }}">{{ __('site.pricing.trialDays', ['count' => $plan->trial_duration_days]) }}</p>@endif
                                    @if($modules)
                                        <ul class="mt-6 flex-1 space-y-2.5 text-sm {{ $plan->is_default ? 'text-blue-50' : 'text-slate-600' }}">
                                            @foreach (array_slice($modules, 0, 6) as $module)
                                                <li class="flex items-start gap-2.5">
                                                    <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $plan->is_default ? 'text-blue-300' : 'text-blue-500' }}" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 4.5" /></svg>
                                                    {{ $moduleMeta[$module]['label'] ?? \Illuminate\Support\Str::headline(str_replace(['.', '_'], ' ', $module)) }}
                                                </li>
                                            @endforeach
                                            @if(count($modules) > 6)<li class="pl-6 text-xs opacity-70">+{{ count($modules) - 6 }} more included</li>@endif
                                        </ul>
                                    @endif
                                    <div class="mt-7"><x-site.button href="/app/register" :variant="$plan->is_default ? 'light' : 'primary'" class="w-full">{{ __('site.actions.startFree') }}</x-site.button></div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
                @break

            @case('faq')
                <section class="mx-auto max-w-3xl px-4 py-14 sm:px-6">
                    <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? 'Questions, answered'" :lead="$block['lead'] ?? null" align="center" class="mb-8" />
                    <div class="space-y-3">
                        @foreach ($block['items'] ?? [] as $faq)
                            <details class="group rounded-2xl border border-slate-200/80 bg-white px-5 shadow-sm open:border-blue-200 open:shadow-md">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 text-[15px] font-bold text-slate-900 [&::-webkit-details-marker]:hidden">
                                    {{ $faq['q'] ?? '' }}
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-open:rotate-45 group-open:bg-blue-600 group-open:text-white" aria-hidden="true">+</span>
                                </summary>
                                <p class="pb-5 leading-relaxed text-slate-600">{{ $faq['a'] ?? '' }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>
                @break

            @case('comparison')
                <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6">
                    @if(!empty($block['heading']))
                        <h2 class="mb-8 text-center text-3xl font-extrabold tracking-tight text-slate-900">{{ $block['heading'] }}</h2>
                    @endif
                    <div class="overflow-x-auto rounded-2xl border border-slate-200/80 shadow-sm">
                        <table class="w-full min-w-[560px] border-collapse bg-white text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50/70">
                                    <th scope="col" class="px-5 py-4 font-semibold text-slate-500"></th>
                                    @foreach ($block['columns'] ?? [] as $column)
                                        <th scope="col" class="px-5 py-4 font-extrabold tracking-tight text-slate-900">{{ $column['heading'] ?? '' }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($block['rows'] ?? [] as $row)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <th scope="row" class="px-5 py-3.5 font-semibold text-slate-700">{{ $row['label'] ?? '' }}</th>
                                        @foreach ($block['columns'] ?? [] as $ci => $column)
                                            @php($cell = trim((string) ($row['values'][$ci] ?? '')))
                                            <td class="px-5 py-3.5 text-slate-600">
                                                @if(strtolower($cell) === 'yes')
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" role="img" aria-label="{{ __('site.comparison.included') }}">✓</span>
                                                @elseif(strtolower($cell) === 'no' || $cell === '')
                                                    <span class="text-slate-300" aria-label="{{ __('site.comparison.notIncluded') }}">—</span>
                                                @else
                                                    {{ $cell }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                @break

            @case('contact_form')
                <section class="mx-auto max-w-2xl px-4 py-14 sm:px-6">
                    <div class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-10">
                        <x-site.section-heading :eyebrow="$block['eyebrow'] ?? null" :heading="$block['heading'] ?? __('site.contact.heading')" :lead="$block['lead'] ?? null" align="center" class="mb-8" />
                        <form data-contact-form data-source="{{ $block['source'] ?? 'contact' }}" class="space-y-4" novalidate>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="cf-name">{{ __('site.contact.name') }}</label>
                                    <input id="cf-name" name="name" type="text" required autocomplete="name" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-blue-400">
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="cf-email">{{ __('site.contact.email') }}</label>
                                    <input id="cf-email" name="email" type="email" required autocomplete="email" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-blue-400">
                                </div>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="cf-company">{{ __('site.contact.company') }} <span class="font-normal text-slate-400">{{ __('site.contact.optional') }}</span></label>
                                <input id="cf-company" name="company" type="text" autocomplete="organization" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-blue-400">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700" for="cf-message">{{ __('site.contact.message') }}</label>
                                <textarea id="cf-message" name="message" required rows="4" minlength="10" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-blue-400"></textarea>
                            </div>
                            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                            <p data-contact-error class="hidden rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert"></p>
                            <x-site.button type="submit" size="lg" class="w-full" data-contact-submit>{{ ($block['source'] ?? 'contact') === 'demo' ? __('site.contact.submitDemo') : __('site.contact.submit') }}</x-site.button>
                        </form>
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
