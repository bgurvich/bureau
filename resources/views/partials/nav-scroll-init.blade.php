{{-- Pre-paint scroll: centers the active nav link in view before the browser
     first paints, so a long sidebar doesn't render with the active item below
     the fold. Must stay inline + placed after the nav items so the DOM is
     parsed by the time the script runs. --}}
<script @if (app()->bound('csp.nonce')) nonce="{{ app('csp.nonce') }}" @endif>
    (function () {
        var nav = document.getElementById('main-nav');
        if (!nav) return;
        var el = nav.querySelector('[aria-current="page"]');
        if (!el) return;
        if (el.offsetTop + el.offsetHeight > nav.clientHeight) {
            nav.scrollTop = el.offsetTop - (nav.clientHeight - el.offsetHeight) / 2;
        }
    })();
</script>
