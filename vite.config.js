import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        // Vite emits asset URLs using server.host verbatim unless origin is set.
        // 0.0.0.0 isn't a valid destination in browsers, so explicitly override:
        // - on desktop (no env set) → http://localhost:5173 as before
        // - on phone: export VITE_HOST=<windows-lan-ip> before composer dev
        origin: `http://${process.env.VITE_HOST || 'localhost'}:5173`,
        // Laravel is served on :8000 but assets come from :5173 — different origins,
        // so allow CORS from anywhere in dev.
        cors: { origin: /./ },
        hmr: process.env.VITE_HOST ? { host: process.env.VITE_HOST } : undefined,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
