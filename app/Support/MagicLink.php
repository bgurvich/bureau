<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Generates per-user signed URLs that auto-authenticate the recipient on
 * click, then redirect to an intended destination inside Secretaire.
 *
 * Used by notification channels (reminder email, Slack, weekly digest) so
 * a user who taps a link on their phone lands inside the app without
 * stopping to type a password. The signature is bound to APP_KEY, expires
 * in 48h, and the redirect path is restricted to same-origin (see
 * MagicLinkController::consume for the validation).
 */
final class MagicLink
{
    /**
     * Default TTL for notification-embedded links. Long enough to cover the
     * "I opened the email on Sunday morning" case without letting a leaked
     * mailbox serve as an indefinite backdoor. Much longer than the 15-min
     * interactive sign-in link because these are delivered asynchronously.
     */
    private const DEFAULT_TTL_HOURS = 48;

    /**
     * Build a signed URL that logs $user in and redirects to $routeName.
     * `$routeName` is a Laravel route name; `$routeParams` are its args.
     * Anything in $routeParams is serialised into the signed payload — don't
     * pass PII you wouldn't put in an email subject line.
     *
     * @param  array<string, int|string>  $routeParams
     */
    public static function to(
        User $user,
        string $routeName,
        array $routeParams = [],
        ?int $ttlHours = null,
    ): string {
        $destination = route($routeName, $routeParams, absolute: false);

        return URL::temporarySignedRoute(
            'magic-link.consume',
            now()->addHours($ttlHours ?? self::DEFAULT_TTL_HOURS),
            [
                'user' => $user->id,
                'redirect' => $destination,
            ],
        );
    }

    /**
     * Same as to() but wraps a literal path rather than a named route.
     * Useful for dashboards / calendar deep-links that aren't full routes.
     * Path must start with "/" and stay same-origin when the consume
     * controller validates it.
     */
    public static function toPath(
        User $user,
        string $path,
        ?int $ttlHours = null,
    ): string {
        return URL::temporarySignedRoute(
            'magic-link.consume',
            now()->addHours($ttlHours ?? self::DEFAULT_TTL_HOURS),
            [
                'user' => $user->id,
                'redirect' => $path,
            ],
        );
    }
}
