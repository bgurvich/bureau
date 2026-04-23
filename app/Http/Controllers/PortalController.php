<?php

namespace App\Http\Controllers;

use App\Models\PortalGrant;
use App\Support\PortalActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Entry + exit for the bookkeeper portal.
 *
 *   GET /portal/{token} — consume the one-time link the household owner
 *   shared; sets a session flag + redirects to /portal. Revoked or
 *   expired tokens land on the "expired" page.
 *
 *   POST /portal/logout — clears the session flag. The grant itself
 *   stays valid until the owner revokes it.
 *
 *   GET /portal/expired — static explanation page; shown when a token
 *   fails validation or a session falls out of scope mid-browse.
 */
final class PortalController extends Controller
{
    public function consume(Request $request, string $token): RedirectResponse
    {
        $grant = PortalGrant::findByToken($token);
        if ($grant === null) {
            return redirect()->route('portal.expired');
        }

        $grant->touchSeen();
        $request->session()->put('portal_grant_id', $grant->id);
        // Explicit regenerate — same hygiene as post-login to stop
        // session-fixation if the owner ever shared a URL over a
        // channel that leaked an anonymous session id.
        $request->session()->regenerate();

        PortalActivityLog::record('consumed_token', $grant, $request);

        return redirect()->route('portal.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $grantId = $request->session()->get('portal_grant_id');
        $grant = $grantId ? PortalGrant::query()->withoutGlobalScope('household')->find($grantId) : null;
        if ($grant) {
            PortalActivityLog::record('signed_out', $grant, $request);
        }

        $request->session()->forget('portal_grant_id');

        return redirect()->route('portal.expired');
    }

    public function expired(): View
    {
        return view('portal.expired');
    }
}
