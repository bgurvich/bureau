{{-- Reusable tag input. Wires to Inspector's shared $tag_list. --}}
<div>
    <label for="i-tags" class="mb-1 block text-xs text-neutral-400">{{ __('Tags') }}</label>
    <input wire:model="tag_list" id="i-tags" type="text"
           placeholder="#tax-2026 #home urgent"
           autocomplete="off" spellcheck="false"
           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    <p class="mt-1 text-[11px] text-neutral-500">{{ __('Space- or comma-separated. Optional # prefix. New tags are created on save.') }}</p>
</div>
