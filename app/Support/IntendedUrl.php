<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for session('url.intended') — Laravel's post-login target.
 *
 * The PWA service worker registers from a mobile layout and pre-fetches
 * /m for its shell cache. If the fetch runs unauthenticated (first visit,
 * expired session, etc.), auth middleware saves /m as the intended URL
 * and the next password/magic/social/dev login then honors it — which
 * drops the user into the mobile layout on desktop. Call dropMobileShell()
 * at the top of each login success path so the dashboard wins.
 */
final class IntendedUrl
{
    /**
     * Forget the intended URL if it points at the mobile shell.
     * No-op otherwise — legitimate deep links ("log in, then take me to
     * /bills") still work.
     */
    public static function dropMobileShell(): void
    {
        $intended = session('url.intended');
        if (! is_string($intended)) {
            return;
        }

        if (preg_match('#^(?:https?://[^/]+)?/m(?:/|$)#', $intended) === 1) {
            session()->forget('url.intended');
        }
    }
}
