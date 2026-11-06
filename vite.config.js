import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/main.jsx',
                // Public marketing site (server-rendered Blade): its own
                // Tailwind entry + framework-free behaviour script.
                'resources/css/site.css',
                'resources/js/site.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    build: {
        rollupOptions: {
            output: {
                // Libraries change a few times a year, app code on every deploy. Giving the stable
                // ones their own files keeps them in the browser cache across releases instead of
                // re-downloading them inside the entry chunk each time. Only packages that already
                // sit in the entry are grouped: charts/editor stay in their lazy route chunks.
                manualChunks(id) {
                    if (!id.includes('node_modules')) return undefined;
                    if (/node_modules\/(react|react-dom|scheduler|react-router|react-router-dom|@remix-run)\//.test(id)) return 'vendor-react';
                    if (/node_modules\/(i18next|react-i18next)\//.test(id)) return 'vendor-i18n';
                    if (/node_modules\/(pusher-js|laravel-echo)\//.test(id)) return 'vendor-realtime';
                    if (/node_modules\/axios\//.test(id)) return 'vendor-http';
                    return undefined;
                },
            },
        },
    },
    server: {
        // Docker dev: the browser must reach Vite at a host-visible URL, not
        // the container's 0.0.0.0 (set by docker-compose's `vite` service).
        origin: process.env.VITE_DEV_SERVER_ORIGIN || undefined,
        cors: true,
        watch: {
            // Playwright writes trace .html files under tests/e2e while a suite runs; without
            // this Vite full-reloads the page under test on every write.
            ignored: ['**/storage/framework/views/**', '**/tests/e2e/**'],
            // Bind mounts on Docker Desktop / network drives don't emit file events.
            usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
        },
    },
});
