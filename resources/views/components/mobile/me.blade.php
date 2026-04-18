<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Me'])]
class extends Component
{
    //
};
?>

<div class="space-y-4">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Me') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ auth()->user()?->email }}</p>
    </header>

    <ul class="overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-900/60 divide-y divide-neutral-800">
        <li>
            <a href="{{ route('profile') }}"
               class="flex items-center justify-between px-4 py-3 text-sm text-neutral-200 hover:bg-neutral-800/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                <span>{{ __('Profile') }}</span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('dashboard') }}"
               class="flex items-center justify-between px-4 py-3 text-sm text-neutral-200 hover:bg-neutral-800/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                <span>{{ __('Desktop view') }}</span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm text-rose-400 hover:bg-neutral-800/60 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-neutral-300">
                    <span>{{ __('Sign out') }}</span>
                </button>
            </form>
        </li>
    </ul>
</div>
