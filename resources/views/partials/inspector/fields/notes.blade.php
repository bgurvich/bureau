{{-- Reusable notes textarea. Wires to Inspector's shared $notes property. --}}
<div>
    <label for="i-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
    <textarea wire:model="notes" id="i-notes" rows="3"
              class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
</div>
