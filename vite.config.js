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
