<?php

namespace App\Support;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records authentication attempts — successes and failures — to the
 * login_events table. Invoked explicitly at each success site (so we can
 * attribute the correct method: password, magic_link, passkey, social:*)
 * and from the Illuminate\Auth\Events\Failed listener for password misses.
 */
class LoginRecorder
{
    public const METHOD_PASSWORD = 'password';

    public const METHOD_MAGIC_LINK = 'magic_link';

    public const METHOD_PASSKEY = 'passkey';

    public static function success(string $method, ?User $user, ?Request $request = null): void
    {
        self::write($method, true, null, $user?->id, $user?->email, $request);
    }

    public static function failure(string $method, string $reason, ?string $email, ?Request $request = null): void
    {
        self::write($method, false, $reason, null, $email, $request);
    }

    private static function write(
        string $method,
        bool $succeeded,
        ?string $reason,
        ?int $userId,
        ?string $email,
        ?Request $request,
    ): void {
        $request ??= request();

        LoginEvent::create([
            'user_id' => $userId,
            'email' => $email ? mb_substr($email, 0, 255) : null,
            'method' => mb_substr($method, 0, 32),
            'succeeded' => $succeeded,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
