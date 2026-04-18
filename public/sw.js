// Minimal service worker: cache the mobile shell so the PWA is installable.
// No offline write support by design — capture is online-only.

const CACHE = 'bureau-shell-v1';
const SHELL = ['/m', '/manifest.webmanifest', '/icon.svg', '/icon-192.png', '/icon-512.png'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') {
        return;
    }
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    // Network-first for everything; fall back to cache if offline so the shell still loads.
    e.respondWith(
        fetch(req).catch(() => caches.match(req).then((r) => r || caches.match('/m')))
    );
});
