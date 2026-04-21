<?php

namespace App\Http\Controllers\WebAuthn;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

final class WebAuthnRegisterController extends Controller
{
    public function options(AttestationRequest $request): Responsable
    {
        return $request->fastRegistration()->toCreate();
    }

    public function register(AttestedRequest $request): JsonResponse
    {
        // The front end pre-fills the alias with `guessDeviceLabel()`, so the
        // client-side path almost always supplies something. Fall back to a
        // server-side UA guess only when the alias arrives blank (e.g. the
        // user cleared the field before submitting, or a non-browser client
        // is registering). Last resort: a timestamped generic label so two
        // blank registrations don't collide to the same name.
        $alias = trim((string) $request->input('alias'));
        if ($alias === '') {
            $alias = self::labelFromUserAgent((string) $request->userAgent())
                ?: __('Passkey :when', ['when' => now()->format('M j · H:i')]);
        }

        $id = $request->save(['alias' => $alias]);

        return response()->json(['id' => $id, 'alias' => $alias]);
    }

    /** Return "Browser on OS" or "" when neither can be identified. */
    private static function labelFromUserAgent(string $ua): string
    {
        if ($ua === '') {
            return '';
        }
        $platform = match (true) {
            (bool) preg_match('/iPhone/', $ua) => 'iPhone',
            (bool) preg_match('/iPad/', $ua) => 'iPad',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/Mac OS X/', $ua) => 'Mac',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/Linux/', $ua) => 'Linux',
            default => 'device',
        };
        $browser = match (true) {
            (bool) preg_match('#Edg/#', $ua) => 'Edge',
            (bool) preg_match('#OPR/#', $ua) => 'Opera',
            (bool) preg_match('/Firefox/', $ua) => 'Firefox',
            (bool) preg_match('/Chrome/', $ua) => 'Chrome',
            (bool) preg_match('/Safari/', $ua) => 'Safari',
            default => '',
        };

        return $browser !== '' ? "{$browser} on {$platform}" : '';
    }
}
