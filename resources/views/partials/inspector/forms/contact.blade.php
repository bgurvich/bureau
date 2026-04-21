<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ct-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="kind" id="i-ct-kind"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::contactKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ct-dn" class="mb-1 block text-xs text-neutral-400">{{ __('Display name') }}</label>
            <input wire:model="display_name" id="i-ct-dn" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('display_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>
    @if ($kind === 'person')
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-ct-fn" class="mb-1 block text-xs text-neutral-400">{{ __('First name') }}</label>
                <input wire:model="first_name" id="i-ct-fn" type="text"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <div>
                <label for="i-ct-ln" class="mb-1 block text-xs text-neutral-400">{{ __('Last name') }}</label>
                <input wire:model="last_name" id="i-ct-ln" type="text"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
        </div>
    @else
        <div>
            <label for="i-ct-org" class="mb-1 block text-xs text-neutral-400">{{ __('Organization') }}</label>
            <input wire:model="organization" id="i-ct-org" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    @endif
    <div>
        <label for="i-ct-email" class="mb-1 block text-xs text-neutral-400">{{ __('Emails (comma separated)') }}</label>
        <input wire:model="email" id="i-ct-email" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div>
        <label for="i-ct-phone" class="mb-1 block text-xs text-neutral-400">{{ __('Phones (comma separated)') }}</label>
        <input wire:model="phone" id="i-ct-phone" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div class="grid grid-cols-3 gap-3 text-xs">
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="favorite" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Favorite') }}
        </label>
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="is_vendor" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Vendor') }}
        </label>
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="is_customer" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Customer') }}
        </label>
    </div>
    <div>
        <label for="i-ct-tid" class="mb-1 block text-xs text-neutral-400">{{ __('Tax ID') }}</label>
        <input wire:model="tax_id" id="i-ct-tid" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    <div>
        <label for="i-ct-patterns" class="mb-1 block text-xs text-neutral-400">{{ __('Match patterns') }}</label>
        <textarea wire:model="contact_match_patterns" id="i-ct-patterns" rows="3"
                  placeholder="costco&#10;wholesale.*costco&#10;cstco"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        <p class="mt-1 text-[11px] text-neutral-500">
            {{ __('One regex per line, case-insensitive. Wins over name-based matching, so a renamed contact stays linked to its transactions.') }}
        </p>
    </div>
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
