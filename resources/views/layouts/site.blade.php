<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle ?? $title ?? $siteName }}</title>
    <meta name="description" content="{{ $metaDescription ?? '' }}">
    @if($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endif
    <style>
        :root { --accent: #6366f1; --ink: #1f2937; --muted: #6b7280; --bg: #f8fafc; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.55; }
        header.site { background: #fff; border-bottom: 1px solid #e5e7eb; }
        header.site .wrap, footer.site .wrap, main .wrap { max-width: 1040px; margin: 0 auto; padding: 0 1.25rem; }
        header.site .wrap { display: flex; align-items: center; justify-content: space-between; height: 64px; }
        header.site a.brand { font-weight: 700; font-size: 1.05rem; color: var(--ink); text-decoration: none; }
        header.site nav a { margin-left: 1.25rem; color: var(--muted); text-decoration: none; font-size: .9rem; }
        header.site nav a:hover { color: var(--accent); }
        main { min-height: calc(100vh - 240px); }
        .block { margin: 2rem 0; }
        .hero { text-align: center; padding: 3.5rem 0 2rem; }
        .hero h1 { font-size: 2.4rem; line-height: 1.15; margin: 0 0 .75rem; }
        .hero p.lead { color: var(--muted); font-size: 1.1rem; max-width: 560px; margin: 0 auto 1.5rem; }
        .cta a, a.btn { display: inline-block; background: var(--accent); color: #fff; padding: .65rem 1.4rem; border-radius: .5rem; text-decoration: none; font-weight: 600; }
        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
        .features .item { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 1.25rem; }
        .features .item h3 { margin: 0 0 .4rem; font-size: 1.05rem; }
        .features .item p { margin: 0; color: var(--muted); }
        .text-block { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 1.5rem; }
        .text-block h2 { margin-top: 0; }
        .cta-band { text-align: center; background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 2.5rem 1.5rem; }
        .cta-band p { color: var(--muted); margin-bottom: 1.25rem; }
        footer.site { background: #111827; color: #9ca3af; }
        footer.site .wrap { display: flex; align-items: center; justify-content: space-between; padding: 1.5rem 1.25rem; font-size: .85rem; }
        footer.site a { color: #d1d5db; text-decoration: none; margin-left: 1rem; }
        footer.site a:hover { color: #fff; }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap">
            <a class="brand" href="/">{{ $siteName }}</a>
            <nav>
                <a href="/">Home</a>
                <a href="/page/privacy">Privacy</a>
                <a href="/page/terms">Terms</a>
                <a href="/app/login">Log in</a>
                <a href="/app/register">Start free</a>
            </nav>
        </div>
    </header>
    <main>
        <div class="wrap">
            @yield('content')
        </div>
    </main>
    <footer class="site">
        <div class="wrap">
            <span>&copy; {{ date('Y') }} {{ $siteName }}</span>
            <span><a href="/page/privacy">Privacy</a><a href="/page/terms">Terms</a><a href="/sitemap.xml">Sitemap</a></span>
        </div>
    </footer>
</body>
</html>