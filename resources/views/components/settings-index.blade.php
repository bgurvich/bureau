<?php

use App\Jobs\PayPalBackfillJob;
use App\Mail\HouseholdInvitationMail;
use App\Models\HouseholdInvitation;
use App\Models\Integration;
use App\Models\User;
use App\Support\CurrentHousehold;
use App\Support\PayPal\PayPalSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Vendor ignore patterns + re-resolve live in the reusable
     * `vendor-ignore-editor` Livewire subcomponent so the same editor
     * can embed on /reconcile and anywhere else without duplicating
     * state management.
     */
    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public ?string $inviteMessage = null;

    public ?string $inviteError = null;

    /**
     * Current user's role in the active household. Only owners can
     * invite, resend, revoke, or remove — every mutator reads this.
     */
    private function currentRole(): ?string
    {
        $h = CurrentHousehold::get();
        if (! $h) {
            return null;
        }
        $pivot = $h->users()->where('users.id', auth()->id())->first()?->pivot;

        return $pivot?->role;
    }

    private function requireOwner(): bool
    {
        return $this->currentRole() === 'owner';
    }

    public function sendInvite(): void
    {
        $this->inviteMessage = null;
        $this->inviteError = null;

        if (! $this->requireOwner()) {
            $this->inviteError = __('Only household owners can invite new members.');

            return;
        }

        $this->validate([
            'inviteEmail' => 'required|email:rfc',
            'inviteRole' => 'required|in:owner,member,viewer',
        ], attributes: ['inviteEmail' => __('email')]);

        $h = CurrentHousehold::get();
        if (! $h) {
            return;
        }

        $email = mb_strtolower(trim($this->inviteEmail));

        // Guard: is this address already on the household? Silently
        // collapse existing pending invites to a single row.
        $alreadyMember = $h->users()
            ->whereRaw('LOWER(users.email) = ?', [$email])
            ->exists();
        if ($alreadyMember) {
            $this->inviteError = __(':email is already a member of this household.', ['email' => $email]);

            return;
        }

        $existingInvite = HouseholdInvitation::where('household_id', $h->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->first();

        if ($existingInvite) {
            $plain = $existingInvite->rotateToken();
            $existingInvite->forceFill(['role' => $this->inviteRole])->save();
            $invite = $existingInvite->fresh();
        } else {
            [$invite, $plain] = HouseholdInvitation::issue($h, $email, $this->inviteRole, auth()->user());
        }

        $acceptUrl = route('invitations.accept', ['token' => $plain]);
        Mail::to($email)->send(new HouseholdInvitationMail(
            acceptUrl: $acceptUrl,
            household: $h,
            invitedBy: auth()->user(),
            inviteeEmail: $email,
            role: $this->inviteRole,
            expiresAt: $invite->expires_at,
        ));

        $this->inviteEmail = '';
        $this->inviteMessage = __('Invitation sent to :email.', ['email' => $email]);
        unset($this->pendingInvitations, $this->members);
    }

    public function resendInvite(int $invitationId): void
    {
        if (! $this->requireOwner()) {
            return;
        }
        $h = CurrentHousehold::get();
        $invite = HouseholdInvitation::where('household_id', $h?->id)->find($invitationId);
        if (! $invite || $invite->isAccepted()) {
            return;
        }

        $plain = $invite->rotateToken();
        $acceptUrl = route('invitations.accept', ['token' => $plain]);
        Mail::to($invite->email)->send(new HouseholdInvitationMail(
            acceptUrl: $acceptUrl,
            household: $invite->household,
            invitedBy: auth()->user(),
            inviteeEmail: $invite->email,
            role: $invite->role,
            expiresAt: $invite->fresh()->expires_at,
        ));

        $this->inviteMessage = __('Invitation resent to :email.', ['email' => $invite->email]);
        unset($this->pendingInvitations);
    }

    public function revokeInvite(int $invitationId): void
    {
        if (! $this->requireOwner()) {
            return;
        }
        $h = CurrentHousehold::get();
        HouseholdInvitation::where('household_id', $h?->id)
            ->whereNull('accepted_at')
            ->where('id', $invitationId)
            ->delete();
        unset($this->pendingInvitations);
    }

    public function removeMember(int $userId): void
    {
        if (! $this->requireOwner()) {
            return;
        }
        $h = CurrentHousehold::get();
        if (! $h) {
            return;
        }

        // Never let the household lose its last owner — otherwise no
        // one can invite / remove members afterwards.
        $ownersLeft = $h->users()->wherePivot('role', 'owner')->count();
        $targetRole = $h->users()->where('users.id', $userId)->first()?->pivot?->role;
        if ($targetRole === 'owner' && $ownersLeft <= 1) {
            $this->inviteError = __('Can\'t remove the last owner. Promote another member first.');

            return;
        }
        // Also block self-removal for owners — the UI should already
        // hide the button, but enforce in case a race or tampered
        // client call lands.
        if ($userId === (int) auth()->id() && $targetRole === 'owner' && $ownersLeft <= 1) {
            return;
        }

        $h->users()->detach($userId);
        unset($this->members);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id:int,name:?string,email:string,role:string,joined_at:?\DateTimeInterface,is_self:bool}>
     */
    #[Computed]
    public function members(): \Illuminate\Support\Collection
    {
        $h = CurrentHousehold::get();
        if (! $h) {
            return collect();
        }

        return $h->users()->orderBy('users.name')->get()->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => (string) $u->email,
            'role' => (string) ($u->pivot->role ?? 'member'),
            'joined_at' => $u->pivot->joined_at ?? null,
            'is_self' => (int) $u->id === (int) auth()->id(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, HouseholdInvitation> */
    #[Computed]
    public function pendingInvitations(): \Illuminate\Support\Collection
    {
        $h = CurrentHousehold::get();
        if (! $h) {
            return collect();
        }

        return HouseholdInvitation::where('household_id', $h->id)
            ->whereNull('accepted_at')
            ->orderByDesc('id')
            ->get();
    }

    public function isOwner(): bool
    {
        return $this->requireOwner();
    }

    /**
     * Household / app-wide integrations only. Per-user mail and calendar
     * connectors render on /profile so each page answers a single question:
     * here, "what does the *app* talk to?" (PayPal, Slack, Twilio, …);
     * there, "what does *this user* have linked?" (their Gmail, Fastmail).
     */
    #[Computed]
    public function integrations()
    {
        return Integration::whereNotIn('kind', ['mail', 'calendar'])
            ->orderBy('provider')->orderBy('label')
            ->get();
    }

    public function disconnectIntegration(int $integrationId): void
    {
        Integration::whereNotIn('kind', ['mail', 'calendar'])
            ->where('id', $integrationId)
            ->delete();
        unset($this->integrations);
    }

    /** Status message after a sync-now / backfill action, cleared on next render. */
    public ?string $paypalMessage = null;

    public ?string $paypalError = null;

    /** Backfill modal state. */
    public ?int $backfillIntegrationId = null;

    public string $backfillFrom = '';

    public function syncPayPalNow(int $integrationId): void
    {
        $this->paypalMessage = null;
        $this->paypalError = null;

        $integration = Integration::where('provider', 'paypal')->find($integrationId);
        if (! $integration) {
            $this->paypalError = __('Integration not found.');

            return;
        }

        try {
            $created = app(PayPalSync::class)->sync($integration);
            $this->paypalMessage = __('PayPal sync done — :n new transaction(s).', ['n' => $created]);
            unset($this->integrations);
        } catch (\Throwable $e) {
            $this->paypalError = __('Sync failed: :msg', ['msg' => $e->getMessage()]);
        }
    }

    public function openBackfill(int $integrationId): void
    {
        $integration = Integration::where('provider', 'paypal')->find($integrationId);
        if (! $integration) {
            return;
        }
        $this->backfillIntegrationId = (int) $integration->id;
        // Default to 3 years back — PayPal's Reporting API retention
        // ceiling. User can narrow the window from there.
        $this->backfillFrom = CarbonImmutable::now()->subYears(3)->toDateString();
        $this->paypalMessage = null;
        $this->paypalError = null;
    }

    public function closeBackfill(): void
    {
        $this->backfillIntegrationId = null;
        $this->backfillFrom = '';
    }

    public function startBackfill(): void
    {
        if (! $this->backfillIntegrationId) {
            return;
        }
        $this->paypalMessage = null;
        $this->paypalError = null;

        try {
            $from = CarbonImmutable::parse($this->backfillFrom)->startOfDay();
        } catch (\Throwable) {
            $this->paypalError = __('Invalid date.');

            return;
        }
        if ($from->isFuture()) {
            $this->paypalError = __('Start date must be in the past.');

            return;
        }

        PayPalBackfillJob::dispatch($this->backfillIntegrationId, $from->toDateString());

        $this->paypalMessage = __('Backfill queued from :from. Progress shows once the worker finishes; new transactions land automatically.', [
            'from' => $from->toDateString(),
        ]);
        $this->closeBackfill();
    }

    public function runBackupNow(): void
    {
        Artisan::call('backup:run', ['--only-db' => true]);
        $name = (string) config('backup.backup.name', 'Laravel');
        session()->flash('backup_ran', __('Backup started — check storage/app/private/:name/ for the latest archive.', ['name' => $name]));
    }

    public function with(): array
    {
        return [
            'backupLastRun' => $this->backupLastRun(),
            'outboundMail' => $this->outboundMail(),
            'localAi' => $this->localAi(),
        ];
    }

    /**
     * Read-only snapshot of outbound-mail config. We never expose secrets —
     * just enough to tell the user "yes, something is wired up" and point
     * them at the ops doc if they need to rotate or rewire.
     *
     * @return array{driver: string, from: ?string, host: ?string, configured: bool}
     */
    private function outboundMail(): array
    {
        $driver = (string) config('mail.default', 'log');
        $from = config('mail.from.address');
        $host = match ($driver) {
            'postmark' => 'api.postmarkapp.com',
            'smtp'     => (string) config('mail.mailers.smtp.host', ''),
            default    => null,
        };

        return [
            'driver' => $driver,
            'from' => is_string($from) && $from !== '' ? $from : null,
            'host' => $host !== '' ? $host : null,
            'configured' => $driver !== 'log' && $driver !== 'array',
        ];
    }

    /**
     * @return array{base_url: ?string, model: ?string, enabled: bool}
     */
    private function localAi(): array
    {
        $base = (string) config('services.lm_studio.base_url', '');
        $model = (string) config('services.lm_studio.model', '');
        $enabled = (bool) config('services.lm_studio.enabled', false);

        return [
            'base_url' => $base !== '' ? $base : null,
            'model' => $model !== '' ? $model : null,
            'enabled' => $enabled && $base !== '',
        ];
    }

    /**
     * Cheapest possible "when was the DB last backed up" — newest file mtime
     * in the spatie backup dir. Good enough for a status badge; if we ever
     * need timezone or size, compute on demand.
     */
    private function backupLastRun(): ?string
    {
        $name = (string) config('backup.backup.name', 'Laravel');
        $dir = storage_path('app/private/'.$name);
        if (! is_dir($dir)) {
            return null;
        }
        $files = glob($dir.'/*.zip');
        if (! $files) {
            return null;
        }
        $newest = max(array_map('filemtime', $files));

        return $newest ? CarbonImmutable::createFromTimestamp($newest)->diffForHumans() : null;
    }
}; ?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Settings')"
        :description="__('App-wide plumbing: household integrations, outbound mail, AI stack, backups. Your personal mail and calendar connectors live on /profile.')">
    </x-ui.page-header>

    @if (session('backup_ran'))
        <div role="status"
             class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300">
            {{ session('backup_ran') }}
        </div>
    @endif

    {{-- Household members + invitations ──────────────────────────────── --}}
    <section aria-labelledby="members-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-4">
            <h3 id="members-heading" class="text-sm font-semibold text-neutral-100">{{ __('Household members') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('People with access to this household. Owners can invite and remove members; everyone can read and edit the shared data.') }}
            </p>
        </header>

        <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
            @foreach ($this->members as $m)
                <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs"
                    wire:key="member-{{ $m['id'] }}">
                    <div class="min-w-0">
                        <div class="text-neutral-100">
                            {{ $m['name'] ?: $m['email'] }}
                            @if ($m['is_self'])
                                <span class="ml-1 text-[10px] uppercase tracking-wider text-neutral-500">{{ __('you') }}</span>
                            @endif
                        </div>
                        <div class="text-[11px] text-neutral-500">
                            {{ $m['email'] }} ·
                            <span class="uppercase tracking-wider">{{ $m['role'] }}</span>
                            @if ($m['joined_at'])
                                · {{ __('joined :when', ['when' => \Carbon\CarbonImmutable::parse($m['joined_at'])->diffForHumans()]) }}
                            @endif
                        </div>
                    </div>
                    @if ($this->isOwner() && ! $m['is_self'])
                        <button type="button"
                                wire:click="removeMember({{ $m['id'] }})"
                                wire:confirm="{{ __('Remove :name from the household?', ['name' => $m['name'] ?: $m['email']]) }}"
                                class="rounded border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-rose-200 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Remove') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($this->isOwner())
            <div class="mt-5 space-y-3 rounded-md border border-neutral-800 bg-neutral-950/40 p-4">
                <h4 class="text-xs font-semibold text-neutral-200">{{ __('Invite someone') }}</h4>
                @if ($this->pendingInvitations->isNotEmpty())
                    <div>
                        <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Pending') }}</div>
                        <ul class="mt-1 divide-y divide-neutral-800 rounded-md border border-neutral-800">
                            @foreach ($this->pendingInvitations as $inv)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs"
                                    wire:key="invite-{{ $inv->id }}">
                                    <div class="min-w-0">
                                        <div class="text-neutral-100">{{ $inv->email }}</div>
                                        <div class="text-[11px] text-neutral-500">
                                            <span class="uppercase tracking-wider">{{ $inv->role }}</span>
                                            @if ($inv->isExpired())
                                                · <span class="text-amber-400">{{ __('expired') }}</span>
                                            @else
                                                · {{ __('expires :when', ['when' => $inv->expires_at->diffForHumans()]) }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" wire:click="resendInvite({{ $inv->id }})"
                                                class="rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Resend') }}
                                        </button>
                                        <button type="button" wire:click="revokeInvite({{ $inv->id }})"
                                                wire:confirm="{{ __('Revoke invitation for :email?', ['email' => $inv->email]) }}"
                                                class="rounded border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-rose-200 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ __('Revoke') }}
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form wire:submit="sendInvite" class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_10rem_auto]" novalidate>
                    <div>
                        <label for="inv-email" class="sr-only">{{ __('Email address') }}</label>
                        <input wire:model="inviteEmail" id="inv-email" type="email"
                               autocomplete="off" placeholder="{{ __('friend@example.com') }}"
                               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        @error('inviteEmail')<div role="alert" class="mt-1 text-[11px] text-rose-400">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="inv-role" class="sr-only">{{ __('Role') }}</label>
                        <select wire:model="inviteRole" id="inv-role"
                                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <option value="member">{{ __('Member') }}</option>
                            <option value="viewer">{{ __('Viewer') }}</option>
                            <option value="owner">{{ __('Owner') }}</option>
                        </select>
                    </div>
                    <button type="submit"
                            class="rounded-md bg-neutral-100 px-4 py-2 text-sm font-medium text-neutral-900 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span wire:loading.remove wire:target="sendInvite">{{ __('Send invite') }}</span>
                        <span wire:loading wire:target="sendInvite">{{ __('Sending…') }}</span>
                    </button>
                </form>

                @if ($inviteMessage)
                    <div role="status" class="text-[11px] text-emerald-300">{{ $inviteMessage }}</div>
                @endif
                @if ($inviteError)
                    <div role="alert" class="text-[11px] text-rose-400">{{ $inviteError }}</div>
                @endif
            </div>
        @endif
    </section>

    {{-- Integrations (household / app-wide) ──────────────────────────── --}}
    <section aria-labelledby="integrations-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-4">
            <h3 id="integrations-heading" class="text-sm font-semibold text-neutral-100">{{ __('Household integrations') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Bank, notification, and other app-wide services. Credentials stored encrypted. Personal mail and calendar accounts belong on :profile.', ['profile' => '/profile']) }}
            </p>
        </header>

        @if ($paypalMessage)
            <div role="status" class="mb-3 rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-xs text-emerald-300">
                {{ $paypalMessage }}
            </div>
        @endif
        @if ($paypalError)
            <div role="alert" class="mb-3 rounded-md border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-xs text-rose-200">
                {{ $paypalError }}
            </div>
        @endif
        @if ($this->integrations->isEmpty())
            <p class="text-xs text-neutral-500">{{ __('No household integrations connected yet.') }}</p>
        @else
            <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
                @foreach ($this->integrations as $int)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs" wire:key="household-integration-{{ $int->id }}">
                        <div class="min-w-0">
                            <div class="text-neutral-100">{{ $int->label ?: $int->provider }}</div>
                            <div class="text-[11px] text-neutral-500">
                                {{ $int->provider }} · {{ $int->kind }} ·
                                <x-ui.row-badge :state="$int->status === 'active' ? 'active' : 'paused'">{{ $int->status }}</x-ui.row-badge>
                                @if ($int->last_synced_at)
                                    · {{ __('synced :when', ['when' => $int->last_synced_at->diffForHumans()]) }}
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($int->provider === 'paypal' && $int->status === 'active')
                                <button type="button" wire:click="syncPayPalNow({{ $int->id }})"
                                        wire:loading.attr="disabled" wire:target="syncPayPalNow"
                                        class="rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="syncPayPalNow">{{ __('Sync now') }}</span>
                                    <span wire:loading wire:target="syncPayPalNow">{{ __('Syncing…') }}</span>
                                </button>
                                <button type="button" wire:click="openBackfill({{ $int->id }})"
                                        class="rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Backfill…') }}
                                </button>
                            @endif
                            <button type="button" wire:click="disconnectIntegration({{ $int->id }})"
                                    wire:confirm="{{ __('Disconnect :n? This removes stored credentials; you\'ll need to reconnect to resume syncing.', ['n' => $int->label ?: $int->provider]) }}"
                                    class="rounded border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-rose-200 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                {{ __('Disconnect') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        <details class="mt-4 rounded-md border border-neutral-800 bg-neutral-950/40 p-3 text-xs text-neutral-400">
            <summary class="cursor-pointer text-neutral-300">{{ __('How to connect') }}</summary>
            <div class="mt-3 space-y-2">
                <p><strong class="text-neutral-200">{{ __('PayPal') }}</strong> —
                   {{ __('Provisioned from the CLI: php artisan integrations:connect-paypal. Credentials are encrypted and paired with a webhook id for signature verification.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Plaid (US banks)') }}</strong> —
                   {{ __('On the roadmap. Secretaire targets Plaid as its single bank-sync provider; no connector ships yet.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Slack / Telegram / Twilio') }}</strong> —
                   {{ __('Notification channels listed on /profile are placeholders — the delivery adapters are still to be built.') }}</p>
            </div>
        </details>
    </section>

    {{-- Bookkeeper portal (read-only grants for external CPA) ─────────── --}}
    <livewire:portal-grants-manager />

    {{-- Outbound mail (Postmark / SMTP / log) ──────────────────────────── --}}
    <section aria-labelledby="outbound-mail-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="outbound-mail-heading" class="text-sm font-semibold text-neutral-100">{{ __('Outbound mail') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('How Secretaire sends reminders, magic-link sign-ins, and alerts. Configured in .env; see docs/ops/outbound-email.md for provider + DNS setup.') }}
                </p>
            </div>
            <x-ui.row-badge :state="$outboundMail['configured'] ? 'active' : 'paused'">
                {{ $outboundMail['configured'] ? __('configured') : __('log only') }}
            </x-ui.row-badge>
        </header>
        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Driver') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['driver'] }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('From address') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['from'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Host') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $outboundMail['host'] ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- Local AI (LM Studio) ────────────────────────────────────────────── --}}
    <section aria-labelledby="local-ai-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="local-ai-heading" class="text-sm font-semibold text-neutral-100">{{ __('Local AI (LM Studio)') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('OCR extraction and other inference run against a local LM Studio server. Configure LM_STUDIO_ENABLED, LM_STUDIO_BASE_URL, and LM_STUDIO_MODEL in .env.') }}
                </p>
            </div>
            <x-ui.row-badge :state="$localAi['enabled'] ? 'active' : 'paused'">
                {{ $localAi['enabled'] ? __('enabled') : __('disabled') }}
            </x-ui.row-badge>
        </header>
        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Base URL') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $localAi['base_url'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Model') }}</dt>
                <dd class="mt-0.5 font-mono text-neutral-200">{{ $localAi['model'] ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- Vendor auto-detect ignore list ───────────────────────────────── --}}
    <section aria-labelledby="vendor-ignore-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3">
            <h3 id="vendor-ignore-heading" class="text-sm font-semibold text-neutral-100">{{ __('Vendor auto-detect · ignore list') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Regex patterns stripped from transaction descriptions before vendor matching. Example: "purchase authorized on \d+/\d+" turns "Purchase authorized on 07/30 Costco" into just "Costco" for matching and auto-created contact names. Use "Re-resolve now" after editing to apply to already-imported transactions.') }}
            </p>
        </header>
        <livewire:vendor-ignore-editor />
    </section>

    {{-- Backups ───────────────────────────────────────────────────────── --}}
    <section aria-labelledby="backup-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
        <header class="mb-3 flex items-baseline justify-between gap-4">
            <div>
                <h3 id="backup-heading" class="text-sm font-semibold text-neutral-100">{{ __('Backups') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('Nightly DB + media snapshots at 03:30 UTC (see :cfg). You can trigger one now.', ['cfg' => 'config/backup.php']) }}
                </p>
            </div>
            <button type="button" wire:click="runBackupNow"
                    wire:confirm="{{ __('Run backup now? DB-only; takes seconds.') }}"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800">
                {{ __('Back up now') }}
            </button>
        </header>
        <div class="font-mono text-[11px] text-neutral-500">
            @if ($backupLastRun)
                {{ __('Last backup: :when', ['when' => $backupLastRun]) }}
            @else
                {{ __('No backup archives found yet.') }}
            @endif
        </div>
    </section>

    {{-- Profile link — keep settings from duplicating per-user preferences. --}}
    <section class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5 text-xs text-neutral-500">
        {{ __('Looking for name / locale / timezone / theme / currency / notification preferences, Gmail or Fastmail connection, or passkeys?') }}
        <a href="{{ route('profile') }}" class="text-sky-300 underline-offset-2 hover:underline">{{ __('They\'re on /profile.') }}</a>
    </section>

    @if ($backfillIntegrationId)
        <div x-cloak x-transition.opacity
             class="fixed inset-0 z-40 bg-black/60"
             wire:click="closeBackfill"
             aria-hidden="true"></div>

        <aside x-cloak
               x-data
               x-on:keydown.escape.window="$wire.closeBackfill()"
               role="dialog" aria-modal="true" aria-label="{{ __('Backfill PayPal history') }}"
               class="fixed left-1/2 top-24 z-50 w-full max-w-md -translate-x-1/2 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-950 shadow-2xl">
            <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
                <h2 class="text-sm font-semibold text-neutral-100">{{ __('Backfill PayPal history') }}</h2>
                <button type="button" wire:click="closeBackfill" aria-label="{{ __('Close') }}"
                        class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>
            <div class="space-y-4 px-5 py-4">
                <p class="text-xs text-neutral-500">
                    {{ __('Pull every PayPal transaction since the date below into this household. PayPal\'s Reporting API retains roughly 3 years; older data isn\'t available via the API (use the CSV export for that). Runs in the background — come back later and look at the Ledger.') }}
                </p>

                <div>
                    <label for="pp-backfill-from" class="mb-1 block text-xs text-neutral-400">{{ __('Start from') }}</label>
                    <input wire:model="backfillFrom" id="pp-backfill-from" type="date" required
                           max="{{ now()->toDateString() }}"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <p class="mt-1 text-[11px] text-neutral-500">
                        {{ __('Default is three years back — PayPal\'s retention ceiling.') }}
                    </p>
                </div>
            </div>
            <footer class="flex items-center justify-end gap-2 border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <button type="button" wire:click="closeBackfill"
                        class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="startBackfill"
                        class="rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="startBackfill">{{ __('Queue backfill') }}</span>
                    <span wire:loading wire:target="startBackfill">{{ __('Queueing…') }}</span>
                </button>
            </footer>
        </aside>
    @endif
</div>
