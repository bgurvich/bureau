<?php

use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public string $theme = 'system';

    public string $locale = 'en';

    public function mount(): void
    {
        $user = auth()->user();
        if ($user) {
            $this->theme = $user->theme ?: 'system';
            $this->locale = $user->locale ?: 'en';
        }
    }

    public function setTheme(string $theme): void
    {
        if (! array_key_exists($theme, User::availableThemes())) {
            return;
        }

        $user = auth()->user();
        if ($user) {
            $user->forceFill(['theme' => $theme])->save();
        }
        $this->theme = $theme;
        $this->dispatch('theme-changed', theme: $theme);
    }

    public function setLocale(string $locale): void
    {
        if (! array_key_exists($locale, User::availableLocales())) {
            return;
        }

        $user = auth()->user();
        if ($user) {
            $user->forceFill(['locale' => $locale])->save();
        }
        $this->locale = $locale;
        $this->redirect(request()->header('Referer') ?: route('dashboard'), navigate: false);
    }
};
?>

<div
    x-data="{ open: false, submenu: null }"
    @keydown.escape.window="open = false; submenu = null"
    class="relative"
>
    @php
        $user = auth()->user();
        $themes = App\Models\User::availableThemes();
        $locales = App\Models\User::availableLocales();
    @endphp

    <button
        type="button"
        @click="open = !open; submenu = null"
        :aria-expanded="open.toString()"
        aria-haspopup="menu"
        aria-label="{{ __('Open user menu') }}"
        class="flex items-center gap-2 rounded-md border border-neutral-800 bg-neutral-900 px-2 py-1.5 text-sm text-neutral-200 hover:border-neutral-700 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
    >
        <span aria-hidden="true"
              class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-neutral-700 text-[10px] font-semibold tracking-wider text-neutral-100">
            {{ $user?->initials() }}
        </span>
        <span class="max-w-[120px] truncate text-xs">{{ $user?->name }}</span>
        <svg aria-hidden="true" class="h-3 w-3 text-neutral-500" viewBox="0 0 12 12" fill="none">
            <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>

    <div
        x-cloak
        x-show="open"
        @click.outside="open = false; submenu = null"
        x-transition.opacity.duration.100ms
        role="menu"
        aria-label="{{ __('User menu') }}"
        class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-md border border-neutral-800 bg-neutral-900 shadow-xl"
    >
        <div class="border-b border-neutral-800 px-3 py-2.5">
            <div class="truncate text-sm text-neutral-100">{{ $user?->name }}</div>
            <div class="truncate text-xs text-neutral-500">{{ $user?->email }}</div>
        </div>

        <div x-show="submenu === null" role="none" class="py-1 text-sm">
            <a href="{{ route('profile') }}"
               role="menuitem"
               class="flex items-center justify-between px-3 py-2 text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span>{{ __('Profile') }}</span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
            <a href="{{ route('settings') }}"
               role="menuitem"
               class="flex items-center justify-between px-3 py-2 text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span>{{ __('Settings') }}</span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
            <button type="button"
                    @click="submenu = 'theme'"
                    role="menuitem"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span>{{ __('Theme') }}</span>
                <span class="flex items-center gap-1 text-xs text-neutral-500">
                    <span>{{ __($themes[$theme]) }}</span>
                    <span aria-hidden="true">›</span>
                </span>
            </button>
            <button type="button"
                    @click="submenu = 'language'"
                    role="menuitem"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span>{{ __('Language') }}</span>
                <span class="flex items-center gap-1 text-xs text-neutral-500">
                    <span>{{ $locales[$locale] ?? $locale }}</span>
                    <span aria-hidden="true">›</span>
                </span>
            </button>

            <form method="POST" action="{{ route('logout') }}" role="none" class="border-t border-neutral-800">
                @csrf
                <button type="submit"
                        role="menuitem"
                        class="flex w-full items-center px-3 py-2 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Sign out') }}
                </button>
            </form>
        </div>

        <div x-show="submenu === 'theme'" role="none" class="py-1 text-sm">
            <button type="button"
                    @click="submenu = null"
                    class="flex w-full items-center gap-1 px-3 py-2 text-left text-xs text-neutral-400 hover:text-neutral-200">
                <span aria-hidden="true">‹</span>
                <span>{{ __('Theme') }}</span>
            </button>
            @foreach ($themes as $value => $label)
                <button type="button"
                        wire:click="setTheme('{{ $value }}')"
                        role="menuitemradio"
                        aria-checked="{{ $theme === $value ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span>{{ __($label) }}</span>
                    @if ($theme === $value)
                        <span aria-hidden="true" class="text-emerald-400">✓</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div x-show="submenu === 'language'" role="none" class="py-1 text-sm">
            <button type="button"
                    @click="submenu = null"
                    class="flex w-full items-center gap-1 px-3 py-2 text-left text-xs text-neutral-400 hover:text-neutral-200">
                <span aria-hidden="true">‹</span>
                <span>{{ __('Language') }}</span>
            </button>
            @foreach ($locales as $value => $label)
                <button type="button"
                        wire:click="setLocale('{{ $value }}')"
                        role="menuitemradio"
                        aria-checked="{{ $locale === $value ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-neutral-200 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span>{{ $label }}</span>
                    @if ($locale === $value)
                        <span aria-hidden="true" class="text-emerald-400">✓</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
