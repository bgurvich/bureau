<?php

use App\Models\User;
use App\Support\CurrentHousehold;
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

    #[Validate('required|string|in:system,light,dark,retro')]
    public string $theme = 'system';

    #[Validate('required|string|size:3|alpha')]
    public string $household_default_currency = 'USD';

    public bool $saved = false;

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
        return [
            'locales' => User::availableLocales(),
            'themes' => User::availableThemes(),
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
            'timezones' => \DateTimeZone::listIdentifiers(),
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
</div>
