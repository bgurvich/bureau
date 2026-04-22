<?php

use App\Models\PortalGrant;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Household-owner-facing management surface for bookkeeper-portal
 * grants. Embedded in /settings. Creates tokens, lists live grants,
 * revokes them, and surfaces the one-time URL once at creation so
 * the owner can copy it out-of-band to the CPA.
 */
new class extends Component
{
    #[Validate('nullable|email|max:255')]
    public string $grantee_email = '';

    #[Validate('nullable|string|max:120')]
    public string $label = '';

    /** Days from now for expiry — common CPA timelines are monthly up to one tax year. */
    #[Validate('required|integer|min:1|max:730')]
    public int $expires_in_days = 90;

    /** One-time URL shown after a successful issue; nulled on next render after copy. */
    public ?string $oneTimeUrl = null;

    #[Computed]
    public function grants(): Collection
    {
        // Preview grants are owner-only scratch rows — hide them so the
        // visible roster stays about real CPA access. Expired ones fall
        // off after their TTL and can be swept by a future cron.
        return PortalGrant::query()
            ->where('is_preview', false)
            ->orderByRaw('revoked_at IS NULL DESC')
            ->orderByDesc('expires_at')
            ->limit(25)
            ->get();
    }

    public function issue(): void
    {
        $data = $this->validate();
        $household = CurrentHousehold::get();
        if (! $household) {
            return;
        }

        [$grant, $raw] = PortalGrant::issue(
            householdId: (int) $household->id,
            expiresAt: CarbonImmutable::now()->addDays($data['expires_in_days']),
            granteeEmail: trim($data['grantee_email']) ?: null,
            label: trim($data['label']) ?: null,
        );

        $this->oneTimeUrl = route('portal.consume', ['token' => $raw]);
        $this->reset(['grantee_email', 'label']);
        $this->expires_in_days = 90;
        unset($this->grants);
    }

    public function revoke(int $id): void
    {
        $grant = PortalGrant::find($id);
        if ($grant) {
            $grant->revoke();
            unset($this->grants);
        }
    }

    /**
     * Owner-only preview — issues a short-lived (1 hour) grant flagged
     * `is_preview` and dispatches a browser event carrying the one-time
     * URL. Alpine opens that URL in a new tab so the owner's main
     * session stays logged in (the portal session regenerates the tab's
     * session id on consume, which would log the owner out if it ran in
     * the same window).
     */
    public function previewAsCpa(): void
    {
        $household = CurrentHousehold::get();
        $user = auth()->user();
        if (! $household || ! $user) {
            return;
        }

        [, $raw] = PortalGrant::issue(
            householdId: (int) $household->id,
            expiresAt: CarbonImmutable::now()->addHour(),
            granteeEmail: $user->email,
            label: __('Owner preview'),
            isPreview: true,
        );

        $this->dispatch('open-portal-preview', url: route('portal.consume', ['token' => $raw]));
    }

    public function dismissOneTimeUrl(): void
    {
        $this->oneTimeUrl = null;
    }
};
?>

<section aria-labelledby="portal-heading"
         x-data
         {{-- Livewire dispatches `open-portal-preview { url }` after it
              stamps the short-lived preview grant. Opening in a new
              window keeps the owner's main session alive (the portal
              consume route regenerates the tab's session id). --}}
         @open-portal-preview.window="window.open($event.detail.url ?? $event.detail[0]?.url, '_blank', 'noopener')"
         class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
    <header class="mb-3 flex items-start justify-between gap-4">
        <div>
            <h2 id="portal-heading" class="text-sm font-semibold text-neutral-100">{{ __('Bookkeeper portal') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Time-boxed read-only access to this household\'s fiscal data for an external CPA or bookkeeper. Share the one-time URL out-of-band (email, Signal). Revoke at any time; grants auto-expire.') }}
            </p>
        </div>
        <button type="button"
                wire:click="previewAsCpa"
                title="{{ __('Opens a short-lived one-hour preview grant in a new tab so you can see exactly what the CPA sees. Your main session stays signed in.') }}"
                class="shrink-0 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('View as CPA would') }}
        </button>
    </header>

    @if ($oneTimeUrl)
        <div role="status" class="mb-4 rounded-md border border-emerald-800/50 bg-emerald-950/30 p-3 text-xs">
            <div class="mb-1 font-medium text-emerald-200">
                {{ __('Grant issued. Copy this link now — it will not be shown again.') }}
            </div>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $oneTimeUrl }}"
                       x-on:click="$el.select()"
                       class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 font-mono text-[11px] text-neutral-100">
                <button type="button" wire:click="dismissOneTimeUrl"
                        class="rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-[11px] text-neutral-300 hover:bg-neutral-800">
                    {{ __('Done') }}
                </button>
            </div>
        </div>
    @endif

    <form wire:submit="issue" class="mb-5 flex flex-wrap items-end gap-3 rounded-md border border-neutral-800 bg-neutral-900/60 p-3">
        <div class="min-w-[12rem] flex-1">
            <label for="pg-email" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Grantee email (display only)') }}</label>
            <input wire:model="grantee_email" id="pg-email" type="email" placeholder="cpa@firm.com"
                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('grantee_email')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div class="min-w-[12rem] flex-1">
            <label for="pg-label" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Label') }}</label>
            <input wire:model="label" id="pg-label" type="text" placeholder="2025 Tax Year"
                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="pg-ttl" class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Expires in (days)') }}</label>
            <input wire:model="expires_in_days" id="pg-ttl" type="number" min="1" max="730"
                   class="mt-1 w-20 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 tabular-nums focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <button type="submit"
                class="rounded-md border border-emerald-800/60 bg-emerald-900/30 px-3 py-1.5 text-xs text-emerald-100 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Issue grant') }}
        </button>
    </form>

    @if ($this->grants->isEmpty())
        <p class="text-xs text-neutral-500">{{ __('No grants yet.') }}</p>
    @else
        <ul class="divide-y divide-neutral-800/60 text-sm">
            @foreach ($this->grants as $g)
                @php($status = $g->revoked_at ? 'revoked' : ($g->expires_at?->isPast() ? 'expired' : 'active'))
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <div class="truncate text-neutral-100">
                            {{ $g->label ?: __('(unlabeled)') }}
                            <span class="ml-1 font-mono text-[10px] text-neutral-500">…{{ $g->token_tail }}</span>
                        </div>
                        <div class="text-[11px] text-neutral-500">
                            @if ($g->grantee_email) {{ $g->grantee_email }} · @endif
                            @if ($status === 'active')
                                <span class="text-emerald-300">{{ __('active') }}</span>
                                · {{ __('expires :d', ['d' => $g->expires_at?->toDateString()]) }}
                            @elseif ($status === 'expired')
                                <span class="text-neutral-500">{{ __('expired :d', ['d' => $g->expires_at?->toDateString()]) }}</span>
                            @else
                                <span class="text-rose-300">{{ __('revoked :d', ['d' => $g->revoked_at?->toDateString()]) }}</span>
                            @endif
                            @if ($g->last_seen_at)
                                · {{ __('last seen :d', ['d' => $g->last_seen_at->diffForHumans()]) }}
                            @endif
                        </div>
                    </div>
                    @if ($status === 'active')
                        <button type="button"
                                wire:click="revoke({{ $g->id }})"
                                wire:confirm="{{ __('Revoke this grant? The CPA loses access immediately.') }}"
                                class="rounded-md border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-[11px] text-rose-200 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Revoke') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
