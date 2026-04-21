<?php

use App\Support\CurrentHousehold;
use App\Support\VendorReresolver;
use Livewire\Component;

/**
 * Inline editor for household.data.vendor_ignore_patterns plus the
 * "Re-resolve now" button. Embeddable anywhere — lives on /settings
 * as the primary home and gets re-used on /reconcile so the user can
 * add a filler-pattern the moment they spot one during reconciliation
 * without bouncing between tabs.
 */
new class extends Component
{
    public string $patterns = '';

    public ?string $savedMessage = null;

    public ?string $reresolveMessage = null;

    public function mount(): void
    {
        $h = CurrentHousehold::get();
        $raw = is_object($h) ? data_get($h->data, 'vendor_ignore_patterns') : null;
        $this->patterns = is_string($raw) ? $raw : '';
    }

    public function save(): void
    {
        $h = CurrentHousehold::get();
        if (! $h) {
            return;
        }
        $data = is_array($h->data) ? $h->data : [];
        $data['vendor_ignore_patterns'] = $this->patterns;
        $h->forceFill(['data' => $data])->save();

        $this->savedMessage = __('Saved.');
        $this->reresolveMessage = null;
    }

    public function reresolve(): void
    {
        $summary = VendorReresolver::run();
        $this->savedMessage = null;
        $this->reresolveMessage = __(
            ':touched touched · :matched matched existing · :created new · :cleared cleared · :skipped looked manual, skipped',
            [
                'touched' => $summary['touched'],
                'matched' => $summary['matched_existing'],
                'created' => $summary['created'],
                'cleared' => $summary['cleared'],
                'skipped' => $summary['skipped_manual'],
            ],
        );

        // Fire a refresh signal so parent pages can clear their own
        // computed caches (transactions list, etc.) without hard-
        // coupling to this component.
        $this->dispatch('vendor-resolve-finished');
    }
};
?>

<div class="space-y-2">
    <form wire:submit.prevent="save" class="space-y-2">
        <label for="vendor-ignore-{{ $_instance?->getId() ?? 'x' }}" class="sr-only">{{ __('Vendor ignore patterns') }}</label>
        <textarea wire:model="patterns"
                  id="vendor-ignore-{{ $_instance?->getId() ?? 'x' }}"
                  rows="4"
                  placeholder="purchase authorized on \d+/\d+&#10;pos purchase&#10;ach transfer (from|to)"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        <div class="flex items-center justify-between gap-3">
            <span class="text-[11px] text-neutral-500">{{ __('One regex per line, case-insensitive. Broken lines are skipped silently.') }}</span>
            <div class="flex items-center gap-2">
                @if ($savedMessage)
                    <span role="status" class="text-[11px] text-emerald-300">{{ $savedMessage }}</span>
                @endif
                <button type="submit"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
                <button type="button"
                        wire:click="reresolve"
                        wire:confirm="{{ __('Re-resolve vendors on every imported transaction?') }}"
                        wire:loading.attr="disabled" wire:target="reresolve"
                        class="rounded-md border border-emerald-800/60 bg-emerald-900/30 px-3 py-1.5 text-xs text-emerald-100 hover:bg-emerald-900/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
                    <span wire:loading.remove wire:target="reresolve">{{ __('Re-resolve now') }}</span>
                    <span wire:loading wire:target="reresolve">{{ __('Working…') }}</span>
                </button>
            </div>
        </div>
    </form>

    @if ($reresolveMessage)
        <div role="status" class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-3 py-2 text-[11px] text-emerald-200">
            {{ $reresolveMessage }}
        </div>
    @endif
</div>
