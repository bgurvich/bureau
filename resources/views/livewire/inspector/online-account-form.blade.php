<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-oa-name" class="mb-1 block text-xs text-neutral-400">{{ __('Service') }}</label>
            <input wire:model="oa_service_name" id="i-oa-name" type="text" required autofocus
                   placeholder="{{ __('Gmail, Amazon, Chase…') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('oa_service_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-oa-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Type') }}</label>
            <select wire:model="oa_kind" id="i-oa-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::onlineAccountKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label for="i-oa-url" class="mb-1 block text-xs text-neutral-400">{{ __('Sign-in URL') }}</label>
        <input wire:model="oa_url" id="i-oa-url" type="url" inputmode="url" autocomplete="off"
               placeholder="https://…"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-oa-email" class="mb-1 block text-xs text-neutral-400">{{ __('Login email') }}</label>
            <input wire:model="oa_login_email" id="i-oa-email" type="email" autocomplete="off"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-oa-user" class="mb-1 block text-xs text-neutral-400">{{ __('Username') }}</label>
            <input wire:model="oa_username" id="i-oa-user" type="text" autocomplete="off"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>

    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Recovery') }}</h4>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-oa-mfa" class="mb-1 block text-xs text-neutral-400">{{ __('MFA method') }}</label>
            <select wire:model="oa_mfa_method" id="i-oa-mfa" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::mfaMethods() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-oa-importance" class="mb-1 block text-xs text-neutral-400">{{ __('Importance') }}</label>
            <select wire:model="oa_importance_tier" id="i-oa-importance" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::importanceTiers() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div>
        <label for="i-oa-recov" class="mb-1 block text-xs text-neutral-400">{{ __('Recovery contact') }}</label>
        <x-ui.searchable-select
            id="i-oa-recov"
            model="oa_recovery_contact_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty"
            edit-inspector-type="contact" />
    </div>

    <hr class="border-neutral-800">
    <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Linked subscription') }}</h4>
    <div>
        <label for="i-oa-contract" class="mb-1 block text-xs text-neutral-400">{{ __('Contract') }}</label>
        <x-ui.searchable-select
            id="i-oa-contract"
            model="oa_linked_contract_id"
            :options="['' => '—'] + $this->contracts->mapWithKeys(fn ($c) => [$c->id => $c->title])->all()"
            placeholder="—"
            edit-inspector-type="contract" />
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Link to the contract/subscription this account auto-charges.') }}</p>
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model="oa_in_case_of_pack" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Include in "in case of" pack') }}
    </label>

    <div>
        <label for="i-oa-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="oa_notes" id="i-oa-notes" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
