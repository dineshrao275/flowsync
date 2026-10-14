<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle ?? $title ?? $siteName ?? config('app.name', 'FlowSync') }}</title>
    <meta name="description" content="{{ $metaDescription ?? '' }}">
    @if(!empty($ogImage))<meta property="og:image" content="{{ $ogImage }}">@endif
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="bg-white font-sans text-slate-600 antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">Skip to content</a>

    <header class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/80 backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="/" class="flex items-center gap-2.5" aria-label="{{ $siteName ?? config('app.name', 'FlowSync') }} home">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-lg font-extrabold text-white shadow-lg shadow-indigo-600/25" aria-hidden="true">S</span>
                <span class="text-lg font-extrabold tracking-tight text-slate-900">{{ $siteName ?? config('app.name', 'FlowSync') }}</span>
            </a>
            <nav class="hidden items-center gap-1 lg:flex" aria-label="Primary">
                <a href="/" class="rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Home</a>
                <a href="/page/privacy" class="rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Privacy</a>
                <a href="/page/terms" class="rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Terms</a>
            </nav>
            <div class="hidden items-center gap-2 lg:flex">
                <x-site.button href="/app/login" variant="ghost">Log in</x-site.button>
                <x-site.button href="/app/register">Start free</x-site.button>
            </div>
            <button id="site-menu-button" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 lg:hidden" aria-expanded="false" aria-controls="site-mobile-menu" aria-label="Toggle menu">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 5.5h14M3 10h14M3 14.5h14" /></svg>
            </button>
        </div>
        <div id="site-mobile-menu" class="hidden border-t border-slate-200/70 bg-white lg:hidden">
            <nav class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-4 sm:px-6" aria-label="Mobile">
                <a href="/" class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Home</a>
                <a href="/page/privacy" class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Privacy</a>
                <a href="/page/terms" class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Terms</a>
                <div class="mt-2 flex flex-col gap-2 border-t border-slate-100 pt-4">
                    <x-site.button href="/app/login" variant="ghost">Log in</x-site.button>
                    <x-site.button href="/app/register">Start free</x-site.button>
                </div>
            </nav>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="bg-slate-950 text-slate-400">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
            <div>
                <a href="/" class="flex items-center gap-2.5" aria-label="{{ $siteName ?? config('app.name', 'FlowSync') }} home">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-lg font-extrabold text-white" aria-hidden="true">S</span>
                    <span class="text-lg font-extrabold tracking-tight text-white">{{ $siteName ?? config('app.name', 'FlowSync') }}</span>
                </a>
                <p class="mt-4 max-w-xs text-sm leading-relaxed">Project management in motion — boards, time tracking, and HR in one calm workspace.</p>
            </div>
            <nav aria-label="Product">
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-500">Product</p>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="/" class="transition hover:text-white">Home</a></li>
                    <li><a href="/app/register" class="transition hover:text-white">Start free</a></li>
                    <li><a href="/app/login" class="transition hover:text-white">Log in</a></li>
                </ul>
            </nav>
            <nav aria-label="Resources">
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-500">Resources</p>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="/sitemap.xml" class="transition hover:text-white">Sitemap</a></li>
                </ul>
            </nav>
            <nav aria-label="Legal">
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-500">Legal</p>
                <ul class="space-y-2.5 text-sm">
                    <li><a href="/page/privacy" class="transition hover:text-white">Privacy</a></li>
                    <li><a href="/page/terms" class="transition hover:text-white">Terms</a></li>
                </ul>
            </nav>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-4 py-5 text-xs sm:flex-row sm:px-6">
                <p>&copy; {{ date('Y') }} {{ $siteName ?? config('app.name', 'FlowSync') }}. All rights reserved.</p>
                <p class="flex gap-4">
                    <a href="/page/privacy" class="transition hover:text-white">Privacy</a>
                    <a href="/page/terms" class="transition hover:text-white">Terms</a>
                </p>
            </div>
        </div>
    </footer>
</body>
</html>
