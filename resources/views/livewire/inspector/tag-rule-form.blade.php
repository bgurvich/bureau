<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-[1fr_auto] gap-3">
        <div>
            <label for="i-tr-tag" class="mb-1 block text-xs text-neutral-400">{{ __('Tag') }}</label>
            <x-ui.searchable-select
                id="i-tr-tag"
                model="tag_rule_tag_id"
                :options="$this->tagPickerOptions"
                placeholder="{{ __('— pick a tag —') }}" />
            @error('tag_rule_tag_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-tr-pri" class="mb-1 block text-xs text-neutral-400">{{ __('Priority') }}</label>
            <input wire:model="tag_rule_priority" id="i-tr-pri" type="number" min="0" max="1000"
                   class="w-20 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-right text-sm font-mono text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-[auto_1fr] gap-3">
        <div>
            <label for="i-tr-type" class="mb-1 block text-xs text-neutral-400">{{ __('Type') }}</label>
            <select wire:model="tag_rule_pattern_type" id="i-tr-type"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="contains">{{ __('contains') }}</option>
                <option value="regex">{{ __('regex') }}</option>
            </select>
        </div>
        <div>
            <label for="i-tr-pat" class="mb-1 block text-xs text-neutral-400">{{ __('Pattern') }}</label>
            <input wire:model="tag_rule_pattern" id="i-tr-pat" type="text" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('tag_rule_pattern')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
    <label class="flex items-center gap-2 text-xs text-neutral-400">
        <input wire:model="tag_rule_active" type="checkbox"
               class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <span>{{ __('Active — attaches matching tags to new transactions') }}</span>
    </label>
</form>
