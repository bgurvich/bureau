<?php

namespace App\Http\Controllers;

use App\Mail\MagicLinkMail;
use App\Models\User;
use App\Support\IntendedUrl;
use App\Support\LoginRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Passwordless sign-in. User submits their email; we generate a signed
 * URL and mail it. Clicking the link verifies the signature + expiry and
 * logs the matching user in. Intentionally quiet on unknown emails so the
 * form isn't an account-enumeration oracle.
 */
final class MagicLinkController extends Controller
{
    private const TTL_MINUTES = 15;

    public function request(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $url = URL::temporarySignedRoute(
                'magic-link.consume',
                now()->addMinutes(self::TTL_MINUTES),
                ['user' => $user->id]
            );
            Mail::to($user->email)->send(new MagicLinkMail($user, $url));
        }

        // Same response regardless of whether the email exists — prevents
        // enumeration attacks while still landing a help message on success.
        return redirect()->route('login')->with(
            'magic_link_sent',
            __('If that email is registered, a sign-in link is on its way. Check your inbox.')
        );
    }

    public function consume(Request $request, int $user): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            LoginRecorder::failure(LoginRecorder::METHOD_MAGIC_LINK, 'invalid-signature', null, $request);

            return redirect()->route('login')->withErrors([
                'email' => __('This sign-in link is expired or invalid. Request a new one.'),
            ]);
        }

        $target = User::find($user);
        if (! $target) {
            LoginRecorder::failure(LoginRecorder::METHOD_MAGIC_LINK, 'user-not-found', null, $request);

            return redirect()->route('login')->withErrors([
                'email' => __('Account not found.'),
            ]);
        }

        Auth::loginUsingId($target->id);
        $request->session()->regenerate();
        LoginRecorder::success(LoginRecorder::METHOD_MAGIC_LINK, $target, $request);

        // Optional `redirect` — notification-embedded magic links carry the
        // intended destination so the user lands on the relevant entity
        // after login instead of the generic dashboard. Enforce same-origin
        // (leading slash, no "//foo.com" open-redirect) to prevent the
        // signed link from being weaponised into an off-site redirect.
        $redirect = $request->query('redirect');
        if (is_string($redirect) && str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return redirect($redirect);
        }

        IntendedUrl::dropMobileShell();

        return redirect()->intended(route('dashboard'));
    }
}
