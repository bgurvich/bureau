<?php

namespace App\Support;

use App\Models\PortalActivityEvent;
use App\Models\PortalGrant;
use Illuminate\Http\Request;

/**
 * Centralized recorder for portal-session audit events. Every call site
 * (consume, middleware page-view, export, logout) lands here so the
 * row shape stays consistent and metadata enrichment (ip, UA fragment,
 * route name) happens in one place.
 */
class PortalActivityLog
{
    /**
     * Record one event. $grant can be null for cases where the session
     * has already died (e.g. expired-redirect), though most call sites
     * will have it.
     *
     * @param  array<string, mixed>  $metadata  caller-supplied extras
     *                                          — route_name, record_count, filename, etc.
     */
    public static function record(
        string $action,
        ?PortalGrant $grant,
        ?Request $request = null,
        array $metadata = [],
    ): void {
        $householdId = $grant?->household_id;
        if ($householdId === null) {
            // No grant = no household scope. Drop rather than risk a
            // cross-tenant mis-attribution.
            return;
        }

        if ($request) {
            // Trim UA — only the useful prefix lands in metadata, to
            // keep the blob readable in the owner's UI.
            $ua = (string) $request->userAgent();
            $metadata['ip'] = $request->ip();
            $metadata['user_agent'] = mb_substr($ua, 0, 160);
        }

        PortalActivityEvent::forceCreate([
            'household_id' => $householdId,
            'portal_grant_id' => $grant->id,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
