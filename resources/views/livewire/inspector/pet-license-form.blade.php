<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-pl-auth" class="mb-1 block text-xs text-neutral-400">{{ __('Issuing authority') }}</label>
        <input wire:model="authority" id="i-pl-auth" type="text" required autofocus
               placeholder="{{ __('Alameda County, CA · NYC DOHMH · …') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('authority')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-pl-num" class="mb-1 block text-xs text-neutral-400">{{ __('License / tag #') }}</label>
        <input wire:model="license_number" id="i-pl-num" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pl-issued" class="mb-1 block text-xs text-neutral-400">{{ __('Issued on') }}</label>
            <input wire:model="issued_on" id="i-pl-issued" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-pl-exp" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="expires_on" id="i-pl-exp" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('expires_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-pl-fee" class="mb-1 block text-xs text-neutral-400">{{ __('Fee') }}
            <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $currency }}</span>
        </label>
        <input wire:model="fee" id="i-pl-fee" type="number" step="0.01"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    @include('partials.inspector.fields.notes')
</form>
