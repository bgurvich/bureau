<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-sg-name" class="mb-1 block text-xs text-neutral-400">{{ __('Goal name') }}</label>
        <input wire:model="savings_name" id="i-sg-name" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('savings_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-sg-target" class="mb-1 block text-xs text-neutral-400">
                {{ __('Target amount') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $savings_currency }}</span>
            </label>
            <input wire:model="savings_target_amount" id="i-sg-target" type="number" step="0.01" min="0" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-right font-mono tabular-nums text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-sg-date" class="mb-1 block text-xs text-neutral-400">{{ __('Target date') }}</label>
            <input wire:model="savings_target_date" id="i-sg-date" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-sg-starting" class="mb-1 block text-xs text-neutral-400">{{ __('Starting amount') }}</label>
            <input wire:model="savings_starting_amount" id="i-sg-starting" type="number" step="0.01" min="0"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-right font-mono tabular-nums text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-sg-saved" class="mb-1 block text-xs text-neutral-400">{{ __('Saved (manual)') }}</label>
            <input wire:model="savings_saved_amount" id="i-sg-saved" type="number" step="0.01" min="0"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-right font-mono tabular-nums text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-sg-acc" class="mb-1 block text-xs text-neutral-400">{{ __('Linked account') }}</label>
        <select wire:model="savings_account_id" id="i-sg-acc"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">{{ __('— manual tracking —') }}</option>
            @foreach (\App\Models\Account::where('is_active', true)->orderBy('name')->get(['id', 'name']) as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="i-sg-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
        <select wire:model="savings_state" id="i-sg-state"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="active">{{ __('active') }}</option>
            <option value="paused">{{ __('paused') }}</option>
            <option value="achieved">{{ __('achieved') }}</option>
            <option value="abandoned">{{ __('abandoned') }}</option>
        </select>
    </div>
</form>
