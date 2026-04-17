<?php

namespace App\Http\Middleware;

use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHousehold
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $household = $user->defaultHousehold
            ?? $user->households()->first();

        if (! $household) {
            abort(403, 'No household associated with this user.');
        }

        CurrentHousehold::set($household);

        return $next($request);
    }
}
