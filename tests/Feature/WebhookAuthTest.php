<?php

it('postmark webhook with unset credentials fails closed in production env', function () {
    // In production with empty creds, middleware should 401 rather than
    // accept anonymous POSTs. testing env is the default for Pest so we
    // simulate "production" by temporarily swapping.
    app()->detectEnvironment(fn () => 'production');
    config()->set('services.postmark.webhook_user', '');
    config()->set('services.postmark.webhook_password', '');

    $this->postJson(route('webhooks.postmark.inbound'), [])
        ->assertStatus(401)
        ->assertJson(['reason' => 'webhook-credentials-unset']);
});

it('postmark webhook with unset credentials passes in the testing env', function () {
    config()->set('services.postmark.webhook_user', '');
    config()->set('services.postmark.webhook_password', '');
    // testing env is the default — no override needed.
    // The request will reach the controller and may 4xx for missing payload
    // shape; we only care that it wasn't stopped at the middleware (no 401).
    $response = $this->postJson(route('webhooks.postmark.inbound'), []);
    expect($response->status())->not->toBe(401);
});

it('postmark webhook rejects wrong credentials with 401', function () {
    config()->set('services.postmark.webhook_user', 'rightuser');
    config()->set('services.postmark.webhook_password', 'rightpass');

    $this->call(
        'POST',
        route('webhooks.postmark.inbound'),
        [],
        [],
        [],
        ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('wronguser:wrongpass')],
    )->assertStatus(401);
});
