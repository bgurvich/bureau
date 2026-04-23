<?php

namespace App\Http\Middleware;

use App\Models\PortalGrant;
use App\Support\CurrentHousehold;
use App\Support\PortalActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /portal/* routes. Looks up the grant id in the session,
 * loads + validates the grant (still active, not-revoked, not-expired),
 * and sets CurrentHousehold to the grant's household so every
 * BelongsToHousehold-scoped query inside the portal is automatically
 * confined to the right tenant.
 *
 * If the grant is gone / revoked / expired, the session key is cleared
 * and the user is bounced to a friendly "expired link" page.
 */
class EnsurePortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $grantId = $request->session()->get('portal_grant_id');
        if (! $grantId) {
            return redirect()->route('portal.expired');
        }

        $grant = PortalGrant::query()
            ->withoutGlobalScope('household')
            ->find($grantId);

        if ($grant === null || ! $grant->isActive()) {
            $request->session()->forget('portal_grant_id');

            return redirect()->route('portal.expired');
        }

        CurrentHousehold::set($grant->household);
        $request->attributes->set('portal_grant', $grant);

        // Log one page_view per GET request. POSTs (form submits) and
        // file downloads register their own events at the controller so
        // we don't double-count.
        if ($request->isMethod('GET') && $request->routeIs('portal.dashboard')) {
            PortalActivityLog::record('page_view', $grant, $request, [
                'route' => (string) $request->route()?->getName(),
            ]);
        }

        return $next($request);
    }
}
