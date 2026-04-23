<?php

declare(strict_types=1);

use App\Support\IntendedUrl;

it('forgets /m itself', function () {
    session(['url.intended' => '/m']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBeNull();
});

it('forgets any /m/* subpath', function () {
    session(['url.intended' => '/m/inbox']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBeNull();
});

it('forgets the absolute form http(s)://host/m…', function () {
    session(['url.intended' => 'https://secretaire.aurnata.com/m/capture']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBeNull();
});

it('keeps non-mobile intended URLs', function () {
    session(['url.intended' => '/bills']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBe('/bills');
});

it('does not clobber /media or other /m-prefixed non-shell paths', function () {
    // /media is NOT the mobile shell — the regex guards on /m/ or end-of-string.
    session(['url.intended' => '/media?mime=pdf']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBe('/media?mime=pdf');

    session(['url.intended' => '/meetings']);
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBe('/meetings');
});

it('is a no-op when intended is unset or non-string', function () {
    session()->forget('url.intended');
    IntendedUrl::dropMobileShell();
    expect(session('url.intended'))->toBeNull();
});
