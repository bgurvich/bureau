<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Inbox'])]
class extends Component
{
    //
};
?>

<div class="space-y-4">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Inbox') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Recent captures waiting to be described.') }}</p>
    </header>

    <div class="rounded-2xl border border-dashed border-neutral-800 bg-neutral-900/40 p-8 text-center text-sm text-neutral-500">
        {{ __('Nothing here yet.') }}
    </div>
</div>
