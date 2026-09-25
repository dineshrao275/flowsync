<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FlowSync Admin</title>
    {{-- Paint the stored color scheme before React mounts (no light flash). Mirrors resources/js/theme.js. --}}
    <script>
        (function () {
            try {
                var raw = window.localStorage.getItem('flowsync.theme');
                if (!raw) return;
                var mode = JSON.parse(raw).mode;
                var dark = mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                var root = document.documentElement;
                root.classList.toggle('dark', dark);
                root.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.jsx'])
</head>
<body class="font-sans antialiased">
    <div id="root"></div>
</body>
</html>