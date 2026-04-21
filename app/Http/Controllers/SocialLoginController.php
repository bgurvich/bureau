<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\IntendedUrl;
use App\Support\LoginRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Social sign-in via Laravel Socialite. Supported providers: google, github,
 * microsoft, apple — any with credentials configured in `services.*`.
 * Unknown provider aliases 404.
 *
 * User-match strategy: match on verified email (Socialite's `getEmail()`
 * from the provider's userinfo endpoint). The email MUST already correspond
 * to a registered User — we do not auto-provision accounts. A provider
 * returning an email we don't know is an authentication failure, not a
 * sign-up trigger. This avoids account-takeover via provider-email
 * misconfiguration or domain hijacking.
 */
final class SocialLoginController extends Controller
{
    private const SUPPORTED = ['google', 'github', 'microsoft', 'apple'];

    public function redirect(string $provider): SymfonyRedirect|RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED, true) || ! $this->providerConfigured($provider)) {
            abort(404);
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED, true) || ! $this->providerConfigured($provider)) {
            abort(404);
        }

        try {
            $social = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            Log::warning('Social login callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            LoginRecorder::failure('social:'.$provider, 'provider-callback-failed', null);

            return redirect()->route('login')->withErrors([
                'email' => __('Sign-in with :p failed. Try again, or use your email.', ['p' => ucfirst($provider)]),
            ]);
        }

        $email = is_string($social->getEmail()) ? strtolower($social->getEmail()) : '';
        if ($email === '') {
            LoginRecorder::failure('social:'.$provider, 'no-email-from-provider', null);

            return redirect()->route('login')->withErrors([
                'email' => __(":p didn't return an email for this account. Use another sign-in method.", ['p' => ucfirst($provider)]),
            ]);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            LoginRecorder::failure('social:'.$provider, 'no-matching-user', $email);

            return redirect()->route('login')->withErrors([
                'email' => __('No Bureau account matches that :p identity. Sign in with email first, then link :p from your profile.', ['p' => ucfirst($provider)]),
            ]);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();
        LoginRecorder::success('social:'.$provider, $user);

        IntendedUrl::dropMobileShell();

        return redirect()->intended(route('dashboard'));
    }

    private function providerConfigured(string $provider): bool
    {
        $id = (string) config("services.{$provider}.client_id", '');
        $secret = (string) config("services.{$provider}.client_secret", '');

        return $id !== '' && $secret !== '';
    }
}
