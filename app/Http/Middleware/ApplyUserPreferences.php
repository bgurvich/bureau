<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            if ($user->locale) {
                app()->setLocale($user->locale);
            }
            if ($user->timezone) {
                config(['app.display_timezone' => $user->timezone]);
            }
        }

        return $next($request);
    }
}
