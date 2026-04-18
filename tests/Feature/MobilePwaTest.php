<?php

it('requires auth to reach the mobile shell', function () {
    $this->get('/m')->assertRedirect(route('login'));
    $this->get('/m/inbox')->assertRedirect(route('login'));
});

it('renders each mobile tab for an authed user', function () {
    authedInHousehold();

    $this->get(route('mobile.capture'))->assertOk()->assertSee('Capture')->assertSee('Photo inventory');
    $this->get(route('mobile.inbox'))->assertOk()->assertSee('Inbox');
    $this->get(route('mobile.search'))->assertOk()->assertSee('Search');
    $this->get(route('mobile.me'))->assertOk()->assertSee('Sign out');
});

it('marks the current mobile tab with aria-current', function () {
    authedInHousehold();

    $response = $this->get(route('mobile.inbox'));
    $response->assertOk()
        ->assertSee('aria-current="page"', false)
        ->assertSee(route('mobile.capture'));
});

it('ships a PWA manifest in public/', function () {
    $path = public_path('manifest.webmanifest');
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($path), true);
    expect($manifest)
        ->toHaveKey('start_url', '/m')
        ->toHaveKey('display', 'standalone')
        ->and($manifest['icons'])->not->toBeEmpty();
});

it('ships a service worker in public/', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
});

it('links the manifest from the mobile layout', function () {
    authedInHousehold();

    $this->get(route('mobile.capture'))
        ->assertSee('/manifest.webmanifest', false)
        ->assertSee('apple-touch-icon', false);
});

it('does not render the desktop sidebar on mobile pages', function () {
    authedInHousehold();

    // Sidebar label is "Primary" (aria-label). Desktop route should have it.
    $desktop = $this->get(route('dashboard'));
    expect(str_contains($desktop->getContent() ?: '', 'aria-label="Primary"'))->toBeTrue();

    // Mobile route must NOT have it.
    $mobile = $this->get(route('mobile.capture'));
    expect(str_contains($mobile->getContent() ?: '', 'aria-label="Primary"'))->toBeFalse();
});
