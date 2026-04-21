<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Support\CurrentHousehold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Interactive OAuth flow for adding a Gmail account as an Integration row.
 *
 * Flow:
 *   1. User hits /integrations/gmail/connect (while authenticated)
 *   2. We redirect to Google's consent screen with access_type=offline so
 *      the response includes a refresh_token we can store.
 *   3. Google redirects back to /integrations/gmail/callback?code=...
 *   4. We exchange the code for tokens, save an Integration row, and go home.
 *
 * Credentials required in .env: GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET.
 * Redirect URI registered in the Google Cloud Console must match
 *   APP_URL/integrations/gmail/callback exactly.
 */
final class GmailOAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function connect(Request $request): RedirectResponse
    {
        $clientId = (string) config('services.google.client_id', '');
        if ($clientId === '') {
            abort(500, 'GOOGLE_CLIENT_ID not set.');
        }

        $state = Str::random(40);
        $request->session()->put('gmail_oauth_state', $state);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('integrations.gmail.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return redirect()->away(self::AUTH_URL.'?'.$params);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('gmail_oauth_state', '');
        $gotState = (string) $request->query('state', '');
        if ($expectedState === '' || ! hash_equals($expectedState, $gotState)) {
            abort(400, 'Invalid OAuth state');
        }

        if ($request->query('error')) {
            return redirect()->route('profile')->withErrors([
                'gmail' => __('Gmail connection denied: :e', ['e' => (string) $request->query('error')]),
            ]);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            abort(400, 'Missing code');
        }

        $clientId = (string) config('services.google.client_id', '');
        $clientSecret = (string) config('services.google.client_secret', '');

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => route('integrations.gmail.callback'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            abort(500, 'Google token exchange failed: '.$response->body());
        }

        $tokens = $response->json();
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        $access = (string) ($tokens['access_token'] ?? '');
        $expiresIn = (int) ($tokens['expires_in'] ?? 3600);

        if ($refresh === '') {
            // Google only returns refresh_token on the first consent; if the
            // user has previously authorized our app they must go to
            // https://myaccount.google.com/permissions and revoke, then retry.
            abort(400, 'Google did not return a refresh_token. Revoke the app at myaccount.google.com/permissions and try again.');
        }

        $household = CurrentHousehold::get();
        if (! $household) {
            abort(500, 'No current household');
        }

        // Fetch user email so the integration row has a friendly label.
        $email = null;
        $profile = Http::withToken($access)->get('https://www.googleapis.com/oauth2/v2/userinfo');
        if ($profile->successful()) {
            $email = $profile->json('email');
        }

        Integration::create([
            'household_id' => $household->id,
            'provider' => 'gmail',
            'kind' => 'mail',
            'label' => is_string($email) ? $email : 'Gmail',
            'credentials' => [
                'refresh_token' => $refresh,
                'access_token' => $access,
                'access_token_expires_at' => time() + $expiresIn,
            ],
            'settings' => [
                'label_ids' => [],   // user picks later via integrations:gmail-labels
                'history_id' => '',  // first run backfills a handful, then cursor forward
            ],
            'status' => 'active',
        ]);

        return redirect()->route('profile')->with('status', __('Gmail connected: :e', ['e' => $email ?? '—']));
    }
}
