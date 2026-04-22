<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-bc-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Category') }}</label>
        <x-ui.searchable-select
            id="i-bc-cat"
            model="budget_category_id"
            :options="$this->categoryPickerOptions"
            :allow-create="true"
            create-method="createCategoryInline"
            placeholder="{{ __('— pick a category —') }}" />
        @error('budget_category_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="i-bc-amt" class="mb-1 block text-xs text-neutral-400">
            {{ __('Monthly cap') }}
            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $budget_currency }}</span>
        </label>
        <input wire:model="budget_monthly_cap" id="i-bc-amt" type="number" step="0.01" min="0" required
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-right font-mono tabular-nums text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('budget_monthly_cap')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <label class="flex items-center gap-2 text-xs text-neutral-400">
        <input wire:model="budget_active" type="checkbox"
               class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <span>{{ __('Active — counts toward the attention radar') }}</span>
    </label>
</form>
