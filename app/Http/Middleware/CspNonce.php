<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a per-request CSP nonce and wires it into Vite + Livewire so
 * every script tag they emit carries `nonce="..."`. SecurityHeaders then
 * reads the nonce from the container and uses it in the CSP, letting us
 * drop `'unsafe-inline'` from script-src.
 *
 * Must run before controllers so Vite/Livewire pick up the nonce during
 * view rendering — registered in bootstrap/app.php.
 */
class CspNonce
{
    public const BINDING = 'csp.nonce';

    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        $this->app->instance(self::BINDING, $nonce);

        Vite::useScriptTagAttributes(['nonce' => $nonce]);
        Vite::useStyleTagAttributes(['nonce' => $nonce]);
        Livewire::useScriptTagAttributes(['nonce' => $nonce]);

        return $next($request);
    }
}
