<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\UserHubPreference;

/**
 * Per-user, per-household last-active-tab memory for hub components.
 *
 * Livewire SFCs (.blade.php with inline `new class extends Component`)
 * aren't scanned by PHPStan, so we can't use a trait (PHPStan would flag
 * it as unused). Static helper methods side-step that while keeping the
 * call sites on each hub down to two lines:
 *
 *   mount():  `$this->tab = HubTabMemory::resolve('pets', $this->tab, 'pets');`
 *   setTab(): `HubTabMemory::remember('pets', $tab);`
 *
 * The `resolve()` helper honours the URL parameter as the strongest
 * signal — `?tab=` always wins so deep links remain deterministic.
 * The stored preference only fills in when the URL didn't carry a value.
 */
final class HubTabMemory
{
    /**
     * Decide which tab to land on. Priority:
     *   1. `$urlTab` from #[Url] — if non-empty, use it.
     *   2. Stored user+household preference for this hub.
     *   3. `$default` — the hub's own first-time landing tab.
     */
    public static function resolve(string $hub, string $urlTab, string $default): string
    {
        if ($urlTab !== '') {
            return $urlTab;
        }
        $remembered = self::remembered($hub);

        return $remembered !== null && $remembered !== '' ? $remembered : $default;
    }

    /** Upsert the user's last-tab pick for this hub. Silently no-op without auth/household. */
    public static function remember(string $hub, string $tab): void
    {
        $user = auth()->user();
        $household = CurrentHousehold::get();
        if (! $user || ! $household || $tab === '') {
            return;
        }

        UserHubPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'household_id' => $household->id,
                'hub_name' => $hub,
            ],
            ['active_tab' => $tab],
        );
    }

    public static function remembered(string $hub): ?string
    {
        $user = auth()->user();
        $household = CurrentHousehold::get();
        if (! $user || ! $household) {
            return null;
        }

        return UserHubPreference::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household->id)
            ->where('hub_name', $hub)
            ->value('active_tab');
    }
}
