<?php

use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\CspNonce;
use App\Http\Middleware\EnsureHousehold;
use App\Http\Middleware\EnsurePortalSession;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyWebhookBasicAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CspNonce prepends so the nonce is bound before Vite/Livewire render.
        // SecurityHeaders appends so it runs after the response is built and
        // can compose CSP using the same nonce.
        $middleware->prepend(CspNonce::class);
        $middleware->append(SecurityHeaders::class);
        // Incoming webhook URLs come from third parties that can't carry a
        // Laravel session cookie or CSRF token. Webhook routes are authed
        // by HTTP basic (postmark) or provider-specific signature (paypal)
        // — see their respective middleware/controllers. They must stay
        // out of the CSRF middleware or production POSTs get rejected
        // with a 419 before reaching signature verification.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
        $middleware->alias([
            'household' => EnsureHousehold::class,
            'preferences' => ApplyUserPreferences::class,
            'portal.session' => EnsurePortalSession::class,
            'webhook.basic' => VerifyWebhookBasicAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
