<?php

it('applies security headers on the login page', function () {
    $response = $this->get(route('login'))->assertOk();

    // SAMEORIGIN (not DENY) so the built-in PDF viewer's same-origin
    // internal frame renders. Cross-origin framing still blocked via
    // `frame-ancestors 'self'` below. Clickjacking posture unchanged.
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        // camera + microphone granted to the first-party app so the
        // mobile capture flows can request them; truly unused sensors
        // stay fully disabled.
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=(self)')
        ->and($response->headers->get('Permissions-Policy'))->toContain('microphone=(self)')
        ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=()')
        ->and($response->headers->get('Permissions-Policy'))->toContain('usb=()')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("object-src 'self'")
        ->and($response->headers->has('X-Powered-By'))->toBeFalse();
});

it('CSP emits a per-request nonce on script-src (not unsafe-inline)', function () {
    $response = $this->get(route('login'))->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toMatch("/script-src[^;]*'nonce-[A-Za-z0-9]{16,}'/")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'");
});

it('CSP nonce changes between requests', function () {
    $a = $this->get(route('login'))->headers->get('Content-Security-Policy');
    $b = $this->get(route('login'))->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", (string) $a, $m1);
    preg_match("/'nonce-([^']+)'/", (string) $b, $m2);

    expect($m1[1] ?? '')->not->toBe('')->not->toBe($m2[1] ?? '');
});

it('applies security headers on authenticated pages', function () {
    authedInHousehold();
    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('default-src');
});

it('does not emit Strict-Transport-Security over http', function () {
    // Test server is http, so HSTS must NOT be present — otherwise browsers
    // would remember the policy and lock out dev installs.
    $response = $this->get(route('login'));
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});
