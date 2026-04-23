<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a conservative set of security response headers — the ones every
 * Laravel app should ship with but the framework doesn't add by default.
 *
 * - X-Frame-Options: SAMEORIGIN + CSP `frame-ancestors 'self'` blocks cross-
 *   origin clickjacking frames while allowing the same-origin PDF viewer.
 * - X-Content-Type-Options: nosniff prevents MIME-type guessing.
 * - Referrer-Policy: strict-origin-when-cross-origin leaks only the origin
 *   to third parties, never the full path.
 * - Permissions-Policy: grants the first-party app access to microphone
 *   + camera (mobile voice note + photo capture) via `(self)`; disables
 *   every other sensor Secretaire doesn't use.
 * - Cross-Origin-Opener-Policy: same-origin isolates the page from popups
 *   opened by/to other origins.
 * - Content-Security-Policy: locks script/style/connect sources to 'self';
 *   Livewire currently relies on inline handlers so 'unsafe-inline' is
 *   included (remove once nonces are wired in).
 * - Strict-Transport-Security: set only when the request arrived over HTTPS
 *   so we don't lock browsers out of a dev install on http://localhost.
 *
 * Finally, removes X-Powered-By if PHP leaked it — some builds still do
 * regardless of expose_php=Off.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // SAMEORIGIN rather than DENY — matches the CSP `frame-ancestors 'self'`
        // directive below. Needed so the built-in PDF viewer (used by the
        // media preview modal via <embed type="application/pdf">) can render:
        // Chromium spins up an internal same-origin frame for it, which DENY
        // blocks even though it's our own origin.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // `(self)` allows the top-level same-origin document to request
        // the capability (first-party mobile capture flows need it —
        // voice-note dictation uses microphone via SpeechRecognition +
        // getUserMedia, and capture-photo/post use camera via
        // <input capture>). `()` fully blocks it and — this bit us —
        // causes Chrome to reject getUserMedia synchronously WITHOUT
        // even surfacing a permission prompt, so no per-site entry
        // ever appears for the user to flip. Sensors we truly don't
        // use stay at `()`.
        $response->headers->set('Permissions-Policy', implode(', ', [
            'accelerometer=()',
            'camera=(self)',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=(self)',
            'payment=()',
            'usb=()',
        ]));

        if (! $response->headers->has('Content-Security-Policy')) {
            // Nonce comes from CspNonce middleware (binds `csp.nonce` on the
            // container so Vite + Livewire emit matching nonce="" attributes).
            // Fall back to 'unsafe-inline' if for some reason the nonce wasn't
            // bound — a response is better than a broken CSP.
            $nonce = app()->bound(CspNonce::BINDING) ? (string) app(CspNonce::BINDING) : '';
            $scriptSrc = $nonce !== ''
                ? "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' 'strict-dynamic'"
                : "script-src 'self' 'unsafe-inline' 'unsafe-eval'";

            // In local dev the Vite server serves assets from a different
            // origin (default http://localhost:5173). Detect via public/hot
            // and extend style/script/connect sources to allow both the dev
            // server (HTTP + WS for HMR). The marker file is the same one
            // Laravel's Vite facade reads to decide between dev + manifest
            // modes, so these broadenings only apply when it's active.
            $hotPath = public_path('hot');
            $viteOrigin = null;
            $viteWs = null;
            if (file_exists($hotPath)) {
                $viteOrigin = trim((string) @file_get_contents($hotPath));
                if ($viteOrigin !== '') {
                    $viteWs = preg_replace('#^https?://#i', 'ws://', $viteOrigin);
                    if (str_starts_with((string) $viteOrigin, 'https:')) {
                        $viteWs = preg_replace('#^https://#i', 'wss://', $viteOrigin);
                    }
                    $scriptSrc .= " {$viteOrigin}";
                }
            }

            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // 'unsafe-eval' is required by Livewire/Alpine expression
                // evaluation until we migrate to their CSP-safe bundles.
                // 'strict-dynamic' lets scripts loaded by the nonced entry
                // chain-load more scripts without nonces (Vite code-splitting).
                $scriptSrc,
                // Keeping style 'unsafe-inline' for now: Livewire + Alpine
                // emit inline styles (x-show visibility, loading states). A
                // nonce-only style-src would need a pass through both libs.
                "style-src 'self' 'unsafe-inline'".($viteOrigin ? " {$viteOrigin}" : ''),
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'".($viteOrigin ? " {$viteOrigin} {$viteWs}" : ''),
                // object-src covers <embed>/<object> so the built-in PDF
                // viewer used by media-index's preview modal renders. Without
                // this, Chromium blocks `<embed type="application/pdf">` and
                // falls back to attempting to frame the current page (which
                // then trips frame-ancestors).
                "object-src 'self'",
                // 'self' rather than 'none' — the PDF viewer runs in an
                // internal child-frame that must be allowed to "frame" the
                // app on the same origin. Cross-origin framing stays blocked.
                "frame-ancestors 'self'",
                "form-action 'self'",
                "base-uri 'self'",
            ]));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
