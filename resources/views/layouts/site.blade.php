@php($site = $site ?? \App\Support\SiteConfig::all())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-site.seo-head
        :title="$seoTitle ?? $title ?? null"
        :description="$metaDescription ?? null"
        :ogImage="$ogImage ?? null"
        :canonical="$canonicalOverride ?? null"
        :robots="$forceRobots ?? 'index, follow'"
        :ogTitle="$ogTitleOverride ?? null"
        :ogDescription="$ogDescriptionOverride ?? null"
    />
    @if(!empty($site['gsc_verification']))<meta name="google-site-verification" content="{{ $site['gsc_verification'] }}">@endif
    @if(!empty($site['ga_measurement_id']))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $site['ga_measurement_id'] }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $site['ga_measurement_id'] }}');</script>
    @endif
    @if(!empty($site['custom_head'])){!! $site['custom_head'] !!}@endif
    <x-site.json-ld :crumbs="$crumbs ?? []" :faq="$faqSchema ?? []" :article="$article ?? null" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >
    @php($siteI18n = [
        'contactError' => __('site.contact.error'),
        'contactThanks' => __('site.contact.thanks'),
        'contactThanksBody' => __('site.contact.thanksBody'),
    ])
    <script type="application/json" id="site-i18n">@json($siteI18n)</script>
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="bg-white font-sans text-slate-600 antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">{{ __('site.skipToContent') }}</a>

    @if(!empty($site['announcement']))
        <div class="bg-blue-600 px-4 py-2 text-center text-sm font-medium text-white">
            @if(!empty($site['announcement_href']))
                <a href="{{ $site['announcement_href'] }}" class="underline decoration-blue-300 underline-offset-2 hover:text-blue-100">{{ $site['announcement'] }}</a>
            @else
                {{ $site['announcement'] }}
            @endif
        </div>
    @endif

    <header class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/80 backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="/" class="flex items-center gap-2.5" aria-label="{{ __('site.brand.home', ['name' => $siteName ?? config('app.name', 'FlowSync')]) }}">
                @if(!empty($site['logo']))
                    <img src="{{ $site['logo']->url() }}" alt="{{ $site['logo']->alt ?: ($siteName ?? config('app.name', 'FlowSync')) }}" class="h-9 w-auto rounded-lg" @if($site['logo']->width)width="{{ $site['logo']->width }}" @endif @if($site['logo']->height)height="{{ $site['logo']->height }}" @endif>
                @else
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-600 text-lg font-extrabold text-white shadow-lg shadow-blue-600/25" aria-hidden="true">S</span>
                @endif
                <span class="text-lg font-extrabold tracking-tight text-slate-900">{{ $siteName ?? config('app.name', 'FlowSync') }}</span>
            </a>
            <nav class="hidden items-center gap-1 lg:flex" aria-label="{{ __('site.nav.primary') }}">
                @foreach($site['nav'] as $link)
                    <a href="{{ $link['href'] }}" class="rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">{{ $link['label'] }}</a>
                @endforeach
            </nav>
            <div class="hidden items-center gap-2 lg:flex">
                <x-site.button href="/app/login" variant="ghost">{{ __('site.actions.logIn') }}</x-site.button>
                <x-site.button href="/app/register">{{ __('site.actions.startFree') }}</x-site.button>
            </div>
            <button id="site-menu-button" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 lg:hidden" aria-expanded="false" aria-controls="site-mobile-menu" aria-label="{{ __('site.nav.toggleMenu') }}">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 5.5h14M3 10h14M3 14.5h14" /></svg>
            </button>
        </div>
        <div id="site-mobile-menu" class="hidden border-t border-slate-200/70 bg-white lg:hidden">
            <nav class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-4 sm:px-6" aria-label="{{ __('site.nav.mobile') }}">
                @foreach($site['nav'] as $link)
                    <a href="{{ $link['href'] }}" class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">{{ $link['label'] }}</a>
                @endforeach
                <div class="mt-2 flex flex-col gap-2 border-t border-slate-100 pt-4">
                    <x-site.button href="/app/login" variant="ghost">{{ __('site.actions.logIn') }}</x-site.button>
                    <x-site.button href="/app/register">{{ __('site.actions.startFree') }}</x-site.button>
                </div>
            </nav>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="bg-slate-950 text-slate-400">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 md:[grid-template-columns:1.4fr_repeat(var(--cols),1fr)]" style="--cols: {{ count($site['footer_columns']) }}">
            <div>
                <a href="/" class="flex items-center gap-2.5" aria-label="{{ __('site.brand.home', ['name' => $siteName ?? config('app.name', 'FlowSync')]) }}">
                    @if(!empty($site['logo']))
                        <img src="{{ $site['logo']->url() }}" alt="{{ $site['logo']->alt ?: ($siteName ?? config('app.name', 'FlowSync')) }}" class="h-9 w-auto rounded-lg">
                    @else
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-600 text-lg font-extrabold text-white" aria-hidden="true">S</span>
                    @endif
                    <span class="text-lg font-extrabold tracking-tight text-white">{{ $siteName ?? config('app.name', 'FlowSync') }}</span>
                </a>
                <p class="mt-4 max-w-xs text-sm leading-relaxed">{{ $site['tagline'] }}</p>
                @if(!empty($site['social']))
                    <p class="mt-5 flex flex-wrap gap-4 text-sm">
                        @foreach($site['social'] as $profile)
                            <a href="{{ $profile['url'] }}" target="_blank" rel="noopener noreferrer" class="transition hover:text-white">{{ $profile['network'] }}</a>
                        @endforeach
                    </p>
                @endif
            </div>
            @foreach($site['footer_columns'] as $column)
                <nav aria-label="{{ $column['heading'] }}">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-500">{{ $column['heading'] }}</p>
                    <ul class="space-y-2.5 text-sm">
                        @foreach($column['links'] as $link)
                            <li><a href="{{ $link['href'] }}" class="transition hover:text-white">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-4 py-5 text-xs sm:flex-row sm:px-6">
                <p>{{ __('site.footer.copyright', ['year' => date('Y'), 'name' => $siteName ?? config('app.name', 'FlowSync')]) }}</p>
                <p class="flex gap-4">
                    <a href="/privacy" class="transition hover:text-white">{{ __('site.footer.privacy') }}</a>
                    <a href="/terms" class="transition hover:text-white">{{ __('site.footer.terms') }}</a>
                </p>
            </div>
        </div>
    </footer>
    @if(!empty($site['custom_body'])){!! $site['custom_body'] !!}@endif
</body>
</html>
