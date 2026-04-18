<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Search'])]
class extends Component
{
    //
};
?>

<div class="space-y-4">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('Search') }}</h1>
    </header>

    <div class="rounded-2xl border border-dashed border-neutral-800 bg-neutral-900/40 p-8 text-center text-sm text-neutral-500">
        {{ __('Search surface coming soon.') }}
    </div>
</div>
