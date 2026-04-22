<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-[1fr_auto] gap-3">
        <div>
            <label for="i-cr-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
            <x-ui.searchable-select
                id="i-cr-cat"
                model="rule_category_id"
                :options="$this->categoryPickerOptions"
                :allow-create="true"
                create-method="createCategoryInline"
                placeholder="{{ __('— pick a category —') }}" />
            @error('rule_category_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-cr-pri" class="mb-1 block text-xs text-neutral-400">{{ __('Priority') }}</label>
            <input wire:model="rule_priority" id="i-cr-pri" type="number" min="0" max="1000"
                   class="w-20 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-right text-sm font-mono text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-[auto_1fr] gap-3">
        <div>
            <label for="i-cr-type" class="mb-1 block text-xs text-neutral-400">{{ __('Type') }}</label>
            <select wire:model="rule_pattern_type" id="i-cr-type"
                    class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="contains">{{ __('contains') }}</option>
                <option value="regex">{{ __('regex') }}</option>
            </select>
        </div>
        <div>
            <label for="i-cr-pat" class="mb-1 block text-xs text-neutral-400">{{ __('Pattern') }}</label>
            <input wire:model="rule_pattern" id="i-cr-pat" type="text" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('rule_pattern')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
    <label class="flex items-center gap-2 text-xs text-neutral-400">
        <input wire:model="rule_active" type="checkbox"
               class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <span>{{ __('Active — applies to new + historical transactions') }}</span>
    </label>
</form>
