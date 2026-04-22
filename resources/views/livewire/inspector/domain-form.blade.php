<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-dom-name" class="mb-1 block text-xs text-neutral-400">{{ __('Domain') }}</label>
            <input wire:model="domain_name" id="i-dom-name" type="text" required autofocus
                   placeholder="example.com"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('domain_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-dom-registrar" class="mb-1 block text-xs text-neutral-400">{{ __('Registrar') }}</label>
            <input wire:model="domain_registrar" id="i-dom-registrar" type="text"
                   placeholder="{{ __('Namecheap, Google…') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-dom-reg-on" class="mb-1 block text-xs text-neutral-400">{{ __('Registered on') }}</label>
            <input wire:model="domain_registered_on" id="i-dom-reg-on" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-dom-exp-on" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="domain_expires_on" id="i-dom-exp-on" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('domain_expires_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs text-neutral-400">&nbsp;</label>
            <label class="flex items-center gap-2 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-200">
                <input wire:model="domain_auto_renew" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
                <span>{{ __('Auto-renew') }}</span>
            </label>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-dom-cost" class="mb-1 block text-xs text-neutral-400">
                {{ __('Annual cost') }}
                <span class="ml-1 font-mono text-xs text-neutral-500">{{ $domain_currency }}</span>
            </label>
            <input wire:model="domain_annual_cost" id="i-dom-cost" type="number" step="0.01" min="0"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-right font-mono tabular-nums text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-dom-registrant" class="mb-1 block text-xs text-neutral-400">{{ __('Registrant') }}</label>
            <x-ui.searchable-select
                id="i-dom-registrant"
                model="domain_registrant_contact_id"
                :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
                placeholder="—" />
        </div>
    </div>

    <div>
        <label for="i-dom-ns" class="mb-1 block text-xs text-neutral-400">{{ __('Nameservers') }}</label>
        <textarea wire:model="domain_nameservers" id="i-dom-ns" rows="3"
                  placeholder="ns1.example.com&#10;ns2.example.com"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        <p class="mt-1 text-xs text-neutral-500">{{ __('One per line.') }}</p>
    </div>

    <div>
        <label for="i-dom-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="domain_notes" id="i-dom-notes" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
