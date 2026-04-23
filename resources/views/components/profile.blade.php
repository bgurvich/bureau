<?php

use App\Models\Integration;
use App\Models\LoginEvent;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('required|string|in:en')]
    public string $locale = 'en';

    #[Validate('required|string|timezone')]
    public string $timezone = 'UTC';

    #[Validate('required|string|max:32')]
    public string $date_format = 'Y-m-d';

    #[Validate('required|string|max:16')]
    public string $time_format = 'H:i';

    #[Validate('required|integer|between:0,6')]
    public int $week_starts_on = 0;

    #[Validate('required|string|in:system,light,dark,dusk,dusk-comfort,retro')]
    public string $theme = 'dusk';

    #[Validate('required|string|size:3|alpha')]
    public string $household_default_currency = 'USD';

    public bool $saved = false;

    /**
     * Matrix keys the notification table shows. Rows = record kinds Secretaire
     * emits reminders for; columns = channels a reminder can go out on.
     * Adding a new kind or channel: drop it in here and the matrix expands.
     *
     * @var array<int, array{key: string, label: string}>
     */
    public array $notificationKinds = [
        ['key' => 'generic_reminder', 'label' => 'Reminders'],
        ['key' => 'task_reminder', 'label' => 'Tasks'],
        ['key' => 'bill_reminder', 'label' => 'Bills'],
        ['key' => 'document_reminder', 'label' => 'Document expirations'],
        ['key' => 'contract_reminder', 'label' => 'Contract renewals'],
        ['key' => 'savingsgoal_reminder', 'label' => 'Savings goals'],
    ];

    /**
     * @var array<int, array{key: string, label: string}>
     */
    public array $notificationChannels = [
        ['key' => 'in_app', 'label' => 'In-app'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'push', 'label' => 'Push'],
        ['key' => 'telegram', 'label' => 'Telegram'],
    ];

    /**
     * Map of "kind:channel" => bool, seeded from the user_notification_preferences
     * table. Missing entries default to enabled (reminders fire until the user
     * explicitly opts out).
     *
     * @var array<string, bool>
     */
    public array $notificationMatrix = [];

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $this->name = $user->name;
        $this->locale = $user->locale;
        $this->timezone = $user->timezone;
        $this->date_format = $user->date_format;
        $this->time_format = $user->time_format;
        $this->week_starts_on = (int) $user->week_starts_on;
        $this->theme = $user->theme;
        $this->household_default_currency = CurrentHousehold::get()?->default_currency ?? 'USD';

        $household = CurrentHousehold::get();
        if ($household) {
            $rows = UserNotificationPreference::where('user_id', $user->id)
                ->where('household_id', $household->id)
                ->get(['kind', 'channel', 'enabled']);

            foreach ($this->notificationKinds as $k) {
                foreach ($this->notificationChannels as $c) {
                    $key = $k['key'].':'.$c['key'];
                    $row = $rows->firstWhere(fn ($r) => $r->kind === $k['key'] && $r->channel === $c['key']);
                    $this->notificationMatrix[$key] = $row ? (bool) $row->enabled : true;
                }
            }
        }
    }

    /**
     * Integrations whose credentials are tied to the user's own accounts
     * (their mailbox, calendars). App-wide integrations like PayPal or
     * Postmark live on /settings instead — scoping keeps each surface
     * answering a single question: "what's connected to *me*?" here,
     * "what's connected to the *app*?" there.
     *
     * @return \Illuminate\Support\Collection<int, Integration>
     */
    #[Computed]
    public function personalIntegrations(): \Illuminate\Support\Collection
    {
        return Integration::whereIn('kind', ['mail', 'calendar'])
            ->orderBy('kind')->orderBy('provider')->orderBy('label')
            ->get();
    }

    public function disconnectIntegration(int $integrationId): void
    {
        Integration::whereIn('kind', ['mail', 'calendar'])
            ->where('id', $integrationId)
            ->delete();
        unset($this->personalIntegrations);
    }

    public function togglePreference(string $kind, string $channel): void
    {
        $user = auth()->user();
        $household = CurrentHousehold::get();
        if (! $user || ! $household) {
            return;
        }

        $key = $kind.':'.$channel;
        $enabled = ! ($this->notificationMatrix[$key] ?? true);
        $this->notificationMatrix[$key] = $enabled;

        UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'household_id' => $household->id, 'kind' => $kind, 'channel' => $channel],
            ['enabled' => $enabled],
        );
    }

    public function save(): void
    {
        $data = $this->validate();
        $currency = strtoupper($data['household_default_currency']);
        unset($data['household_default_currency']);

        $user = auth()->user();
        abort_unless($user, 403);

        $user->forceFill($data)->save();

        $household = CurrentHousehold::get();
        if ($household) {
            $household->forceFill(['default_currency' => $currency])->save();
            $this->household_default_currency = $currency;
        }

        $this->saved = true;
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'locales' => User::availableLocales(),
            'themes' => User::availableThemes(),
            'loginEvents' => $user
                ? LoginEvent::where('user_id', $user->id)->latest('created_at')->limit(10)->get()
                : collect(),
            'dateFormats' => [
                'Y-m-d' => '2026-04-17 (Y-m-d)',
                'm/d/Y' => '04/17/2026 (m/d/Y)',
                'd/m/Y' => '17/04/2026 (d/m/Y)',
                'd.m.Y' => '17.04.2026 (d.m.Y)',
                'd M Y' => '17 Apr 2026 (d M Y)',
                'M j, Y' => 'Apr 17, 2026 (M j, Y)',
            ],
            'timeFormats' => [
                'H:i' => '14:30 (24-hour)',
                'h:i A' => '02:30 PM (12-hour)',
            ],
            'weekStarts' => [
                0 => __('Sunday'),
                1 => __('Monday'),
                6 => __('Saturday'),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }
};
?>

<div class="max-w-2xl">
    <header class="mb-6">
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Profile') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ __('Your display name, localization, and theme.') }}</p>
    </header>

    @if ($saved)
        <div role="status"
             wire:key="saved-banner"
             class="mb-4 rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300">
            {{ __('Saved.') }}
        </div>
    @endif

    <form wire:submit="save"
          class="space-y-5 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6"
          aria-labelledby="profile-heading"
          novalidate>
        <h3 id="profile-heading" class="sr-only">{{ __('Profile settings') }}</h3>

        <div>
            <label for="name" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="name" id="name" type="text" autocomplete="name" required
                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('name')<div id="name-error" role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="locale" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Language') }}</label>
                <select wire:model="locale" id="locale"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach ($locales as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('locale')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="theme" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Theme') }}</label>
                <select wire:model="theme" id="theme"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach ($themes as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                @error('theme')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
        </div>

        <div>
            <label for="timezone" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Timezone') }}</label>
            <x-ui.searchable-select
                id="timezone"
                model="timezone"
                :options="collect($timezones)->mapWithKeys(fn ($tz) => [$tz => $tz])->all()"
                placeholder="{{ __('Pick a timezone…') }}" />
            @error('timezone')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label for="date_format" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Date format') }}</label>
                <select wire:model="date_format" id="date_format"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach ($dateFormats as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('date_format')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="time_format" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Time format') }}</label>
                <select wire:model="time_format" id="time_format"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach ($timeFormats as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('time_format')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="week_starts_on" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Week starts') }}</label>
                <select wire:model="week_starts_on" id="week_starts_on"
                        class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @foreach ($weekStarts as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('week_starts_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
        </div>

        <hr class="border-neutral-800">
        <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Household') }}</h4>
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label for="household_default_currency" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Default currency') }}</label>
                <input wire:model="household_default_currency" id="household_default_currency" type="text" maxlength="3" required
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm uppercase text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <p class="mt-1 text-[11px] text-neutral-500">{{ __('Used as the default when entering money on this household.') }}</p>
                @error('household_default_currency')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-md bg-neutral-100 px-4 py-2 text-sm font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>

    <section class="mt-8 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6"
             aria-labelledby="notifications-heading">
        <header class="mb-4">
            <h3 id="notifications-heading" class="text-sm font-semibold text-neutral-100">{{ __('Notification preferences') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Each row is a reminder category, each column a channel. Unchecked = never deliver for this combination. Defaults to enabled; only explicit opt-outs are stored.') }}
            </p>
        </header>
        <table class="w-full text-xs">
            <thead class="text-[10px] uppercase tracking-wider text-neutral-500">
                <tr>
                    <th class="pb-2 text-left font-medium">{{ __('Kind') }}</th>
                    @foreach ($notificationChannels as $c)
                        <th class="pb-2 text-center font-medium">{{ $c['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800">
                @foreach ($notificationKinds as $k)
                    <tr>
                        <td class="py-2 text-neutral-200">{{ __($k['label']) }}</td>
                        @foreach ($notificationChannels as $c)
                            @php $key = $k['key'].':'.$c['key']; @endphp
                            <td class="py-2 text-center">
                                <label class="inline-flex items-center justify-center">
                                    <span class="sr-only">{{ $k['label'].' · '.$c['label'] }}</span>
                                    <input type="checkbox"
                                           wire:click="togglePreference('{{ $k['key'] }}', '{{ $c['key'] }}')"
                                           @checked($notificationMatrix[$key] ?? true)
                                           class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                </label>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="mt-8 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6"
             aria-labelledby="personal-integrations-heading">
        <header class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 id="personal-integrations-heading" class="text-sm font-semibold text-neutral-100">{{ __('Personal integrations') }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('Mail and calendar accounts linked to you. Credentials are encrypted at rest; disconnect any time.') }}
                </p>
            </div>
            <a href="{{ route('integrations.gmail.connect') }}"
               class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Connect Gmail') }}
            </a>
        </header>

        @if ($this->personalIntegrations->isEmpty())
            <p class="text-xs text-neutral-500">{{ __('No personal mail or calendar accounts linked yet.') }}</p>
        @else
            <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
                @foreach ($this->personalIntegrations as $int)
                    <li class="flex items-start justify-between gap-3 px-3 py-2 text-xs" wire:key="personal-integration-{{ $int->id }}">
                        <div class="min-w-0">
                            <div class="text-neutral-100">{{ $int->label ?: $int->provider }}</div>
                            <div class="text-[11px] text-neutral-500">
                                {{ $int->provider }} · {{ $int->kind }} ·
                                <x-ui.row-badge :state="$int->status === 'active' ? 'active' : ($int->status === 'error' ? 'overdue' : 'paused')">{{ $int->status }}</x-ui.row-badge>
                                @if ($int->last_synced_at)
                                    · {{ __('synced :when', ['when' => $int->last_synced_at->diffForHumans()]) }}
                                @endif
                            </div>
                            @if ($int->status === 'error' && $int->last_error)
                                <div class="mt-1 rounded bg-rose-900/20 px-2 py-1 text-[11px] text-rose-200">
                                    {{ $int->last_error }}
                                </div>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($int->provider === 'gmail' && $int->status === 'error')
                                <a href="{{ route('integrations.gmail.connect') }}"
                                   class="rounded border border-amber-800/50 bg-amber-900/30 px-2 py-1 text-amber-200 hover:bg-amber-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('Reconnect') }}
                                </a>
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
                <p><strong class="text-neutral-200">{{ __('Gmail') }}</strong> —
                   {{ __('Click Connect Gmail above. Google will ask you to sign in and grant read access to your mailbox; tokens are stored encrypted and can be revoked any time.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Fastmail (JMAP)') }}</strong> —
                   {{ __('Fastmail is provisioned from the command line: set FASTMAIL_API_TOKEN then run php artisan integrations:connect-fastmail.') }}</p>
                <p><strong class="text-neutral-200">{{ __('Calendar feeds') }}</strong> —
                   {{ __('Calendar sync is on the roadmap; no connector ships yet.') }}</p>
            </div>
        </details>
    </section>

    <section class="mt-8 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6"
             aria-labelledby="passkeys-heading">
        <header class="mb-4">
            <h3 id="passkeys-heading" class="text-sm font-semibold text-neutral-100">{{ __('Passkeys') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('Sign in with your device\'s biometrics or a security key instead of a password. Passkeys are bound to this site and can\'t be phished.') }}
            </p>
        </header>

        <livewire:passkey-manager />
    </section>

    <section class="mt-8 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6"
             aria-labelledby="login-history-heading">
        <header class="mb-4">
            <h3 id="login-history-heading" class="text-sm font-semibold text-neutral-100">{{ __('Recent sign-ins') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('The last 10 successful and failed sign-in attempts on your account. Something here you don\'t recognize? Change your password or remove unknown passkeys.') }}
            </p>
        </header>
        @if ($loginEvents->isEmpty())
            <p class="text-xs text-neutral-500">{{ __('No sign-in history yet.') }}</p>
        @else
            <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800 text-xs">
                @foreach ($loginEvents as $e)
                    <li class="flex items-center gap-3 px-3 py-2">
                        <span class="inline-flex items-center gap-1">
                            @if ($e->succeeded)
                                <span class="inline-block size-1.5 rounded-full bg-emerald-400" aria-label="{{ __('success') }}"></span>
                            @else
                                <span class="inline-block size-1.5 rounded-full bg-rose-400" aria-label="{{ __('failure') }}"></span>
                            @endif
                            <span class="font-mono text-neutral-300">{{ $e->method }}</span>
                        </span>
                        <span class="text-neutral-500">{{ $e->ip ?: '—' }}</span>
                        <span class="truncate text-neutral-600">{{ \Illuminate\Support\Str::limit((string) $e->user_agent, 60) }}</span>
                        <span class="ml-auto tabular-nums text-neutral-500">{{ $e->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
