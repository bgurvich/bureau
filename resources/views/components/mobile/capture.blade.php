<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Bureau'])]
class extends Component
{
    //
};
?>

<div class="space-y-5">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Capture') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Quick in, describe later.') }}</p>
    </header>

    <ul class="space-y-3">
        <li>
            <a href="{{ route('mobile.capture.inventory') }}"
               class="flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7h4l1-2h8l1 2h4v12H3z"/><circle cx="12" cy="13" r="4"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Photo inventory') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Snap items to describe later.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.capture.note') }}"
               class="flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="9" y="3" width="6" height="12" rx="3"/>
                        <path d="M5 12a7 7 0 0 0 14 0"/>
                        <path d="M12 19v3"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Voice or text note') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Dictate or type, saved as a Note.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.capture.photo') }}"
               class="flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 18 6-6 4 4 3-3 3 3"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Photo or scan') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Receipts, bills, documents.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
        <li>
            <a href="{{ route('mobile.capture.post') }}"
               class="flex w-full items-center gap-4 rounded-2xl border border-neutral-800 bg-neutral-900/60 px-4 py-4 text-left active:bg-neutral-900/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-800 text-neutral-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/>
                    </svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-medium text-neutral-100">{{ __('Post received') }}</span>
                    <span class="block text-xs text-neutral-500">{{ __('Letters, bills, slips. Files under Records.') }}</span>
                </span>
                <span aria-hidden="true" class="text-neutral-500">›</span>
            </a>
        </li>
    </ul>
</div>
