import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

// Injects a sha384 SRI hash onto each entry in Laravel's vite manifest so
// Blade's @vite directive can emit integrity=""+crossorigin="anonymous"
// attributes. Laravel's Vite facade reads the `integrity` key by default.
// Cheaper than a third-party plugin; no new dep.
function sriManifestPlugin() {
    return {
        name: 'secretaire:sri-manifest',
        apply: 'build',
        closeBundle() {
            const manifestPath = resolve(process.cwd(), 'public/build/manifest.json');
            if (!existsSync(manifestPath)) return;
            const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
            const buildDir = resolve(process.cwd(), 'public/build');
            for (const entry of Object.values(manifest)) {
                if (!entry || typeof entry !== 'object' || !entry.file) continue;
                const abs = resolve(buildDir, entry.file);
                if (!existsSync(abs)) continue;
                const digest = createHash('sha384').update(readFileSync(abs)).digest('base64');
                entry.integrity = `sha384-${digest}`;
            }
            writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        sriManifestPlugin(),
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
