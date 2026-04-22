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
    {{-- Roles — multi-value relationship axis (separate from the
         vendor/customer flags above, which are domain roles the
         accounting side cares about). Grouped by category so the
         checkbox grid stays scannable even as the list grows. --}}
    <fieldset class="rounded-lg border border-neutral-800 p-3">
        <legend class="px-1 text-xs text-neutral-400">{{ __('Roles') }}</legend>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (App\Support\Enums::contactRoleGroups() as $groupKey => $group)
                <div>
                    <div class="mb-1 text-[10px] uppercase tracking-wider text-neutral-500">{{ $group['label'] }}</div>
                    <div class="flex flex-wrap gap-2 text-xs text-neutral-300">
                        @foreach ($group['slugs'] as $slug)
                            <label class="flex items-center gap-1.5">
                                <input wire:model="contact_roles" type="checkbox" value="{{ $slug }}"
                                       class="rounded border-neutral-700 bg-neutral-950">
                                <span>{{ App\Support\Enums::contactRoles()[$slug] ?? $slug }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </fieldset>
    <div>
        <label for="i-ct-tid" class="mb-1 block text-xs text-neutral-400">{{ __('Tax ID') }}</label>
        <input wire:model="tax_id" id="i-ct-tid" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>
    @if ($kind === 'person')
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="i-ct-bday" class="mb-1 block text-xs text-neutral-400">{{ __('Birthday') }}</label>
                <input wire:model="birthday" id="i-ct-bday" type="date"
                       class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            </div>
            <label class="mt-6 flex items-center gap-2 text-xs text-neutral-300">
                <input wire:model="birthday_year_known" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
                {{ __('Year known') }}
                <span class="text-neutral-500">{{ __('(uncheck if you only know month/day)') }}</span>
            </label>
        </div>
    @endif
    <div>
        <label for="i-ct-cat" class="mb-1 block text-xs text-neutral-400">{{ __('Default category') }}</label>
        <x-ui.searchable-select
            id="i-ct-cat"
            model="contact_category_id"
            :options="['' => '—'] + $this->categoryPickerOptions"
            placeholder="—"
            edit-inspector-type="category" />
        <p class="mt-1 text-[11px] text-neutral-500">
            {{ __('Transactions with this counterparty auto-inherit this category unless a category is set explicitly.') }}
        </p>
        @if ($id && $contact_category_id)
            <div class="mt-2 flex items-center gap-2">
                <button type="button"
                        wire:click.stop="backfillCategoryToTransactions"
                        wire:confirm="{{ __('Apply this category to every uncategorised transaction for this contact?') }}"
                        class="rounded-md border border-emerald-700/50 bg-emerald-900/20 px-2 py-1 text-[11px] text-emerald-200 hover:bg-emerald-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Apply to uncategorised transactions') }}
                </button>
                @if ($contactBackfillMessage)
                    <span role="status" class="text-[11px] text-emerald-300">{{ $contactBackfillMessage }}</span>
                @endif
            </div>
        @endif
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
