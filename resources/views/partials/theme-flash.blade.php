{{-- Resolve theme SYNCHRONOUSLY before CSS loads to prevent a FOUC when the
     user's pref is light. Mirrors applyTheme() in resources/js/app.ts; keep
     the two in sync. Must stay inline + pre-@vite to run before first paint. --}}
<script @if (app()->bound('csp.nonce')) nonce="{{ app('csp.nonce') }}" @endif>
    (function () {
        var r = document.documentElement;
        var pref = r.dataset.theme || 'system';
        var resolved = pref === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : pref;
        r.dataset.resolvedTheme = resolved;
        r.style.colorScheme = resolved === 'retro' ? 'dark' : resolved;
    })();
</script>
