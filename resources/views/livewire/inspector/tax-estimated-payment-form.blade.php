<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-tp-year" class="mb-1 block text-xs text-neutral-400">{{ __('Tax year') }}</label>
            <select wire:model="tax_year_id" id="i-tp-year" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->taxYears as $ty)
                    <option value="{{ $ty->id }}">{{ $ty->year }} · {{ $ty->jurisdiction }}</option>
                @endforeach
            </select>
            @error('tax_year_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-tp-q" class="mb-1 block text-xs text-neutral-400">{{ __('Quarter') }}</label>
            <select wire:model="quarter" id="i-tp-q" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::taxQuarters() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-tp-due" class="mb-1 block text-xs text-neutral-400">{{ __('Due on') }}</label>
            <input wire:model="due_on" id="i-tp-due" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('due_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-tp-paid" class="mb-1 block text-xs text-neutral-400">{{ __('Paid on') }}</label>
            <input wire:model="paid_on" id="i-tp-paid" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Leave blank until paid.') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-tp-amount" class="mb-1 block text-xs text-neutral-400">{{ __('Amount') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
            </label>
            <input wire:model="amount" id="i-tp-amount" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-tp-acct" class="mb-1 block text-xs text-neutral-400">{{ __('Account') }}</label>
            <select wire:model="account_id" id="i-tp-acct"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @include('partials.inspector.fields.notes')
</form>
