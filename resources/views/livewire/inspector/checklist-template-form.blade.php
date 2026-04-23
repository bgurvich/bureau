<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-ck-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
        <input wire:model="checklist_name" id="i-ck-name" type="text" required autofocus
               placeholder="{{ __('Morning routine') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('checklist_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-ck-desc" class="mb-1 block text-xs text-neutral-400">{{ __('Description') }}</label>
        <textarea wire:model="checklist_description" id="i-ck-desc" rows="2"
                  placeholder="{{ __('Optional. Why this ritual exists, reminders for yourself.') }}"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ck-recur" class="mb-1 block text-xs text-neutral-400">
                {{ __('Recurrence') }}
                @php
                    // Live classification label so the user sees which bucket
                    // their dropdown choice lands them in. Recurring = Habit
                    // (surfaces on /habits with streak); one_off = Checklist
                    // (surfaces on /checklists). custom-with-COUNT=1 is also
                    // one-off under the model's isHabit() rule.
                    $classifiesAs = match (true) {
                        $checklist_recurrence_mode === 'one_off' => 'one_off',
                        $checklist_recurrence_mode === 'custom' => str_contains(strtoupper($checklist_rrule), 'COUNT=1') ? 'one_off' : 'habit',
                        default => 'habit',
                    };
                @endphp
                @if ($classifiesAs === 'habit')
                    <span class="ml-1 rounded-sm bg-emerald-950/40 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-emerald-300">{{ __('habit') }}</span>
                @else
                    <span class="ml-1 rounded-sm bg-sky-950/40 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-sky-300">{{ __('checklist') }}</span>
                @endif
            </label>
            <select wire:model.live="checklist_recurrence_mode" id="i-ck-recur"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="daily">{{ __('Daily') }}</option>
                <option value="weekdays">{{ __('Weekdays (Mon–Fri)') }}</option>
                <option value="weekends">{{ __('Weekends (Sat–Sun)') }}</option>
                <option value="one_off">{{ __('One-off (shopping list, packing, onboarding…)') }}</option>
                <option value="custom">{{ __('Custom RRULE') }}</option>
            </select>
        </div>
        <div>
            <label for="i-ck-tod" class="mb-1 block text-xs text-neutral-400">{{ __('Time of day') }}</label>
            <select wire:model="checklist_time_of_day" id="i-ck-tod"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="morning">{{ __('Morning') }}</option>
                <option value="midday">{{ __('Midday') }}</option>
                <option value="evening">{{ __('Evening') }}</option>
                <option value="night">{{ __('Night') }}</option>
                <option value="anytime">{{ __('Anytime') }}</option>
            </select>
        </div>
    </div>

    @if ($checklist_recurrence_mode === 'custom')
        <div>
            <label for="i-ck-rrule" class="mb-1 block text-xs text-neutral-400">{{ __('RRULE') }}</label>
            <input wire:model="checklist_rrule" id="i-ck-rrule" type="text"
                   placeholder="FREQ=WEEKLY;BYDAY=MO,WE,FR"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Subset of RFC-5545: FREQ, INTERVAL, BYDAY, BYMONTHDAY, COUNT, UNTIL.') }}</p>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ck-dtstart" class="mb-1 block text-xs text-neutral-400">{{ __('Starts on') }}</label>
            <input wire:model="checklist_dtstart" id="i-ck-dtstart" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ck-paused" class="mb-1 block text-xs text-neutral-400">{{ __('Paused until') }}</label>
            <input wire:model="checklist_paused_until" id="i-ck-paused" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Optional. Hides from today until this date passes.') }}</p>
        </div>
    </div>

    <fieldset>
        <legend class="mb-2 text-xs text-neutral-400">
            {{ __('Items') }}
            @if (! empty($checklist_items))
                <span class="ml-1 text-neutral-600">· {{ __('drag to reorder') }}</span>
            @endif
        </legend>
        @if (empty($checklist_items))
            <p class="mb-2 text-xs text-neutral-500">{{ __('Add the steps you want to tick off each run.') }}</p>
        @else
            <x-ui.sortable-list reorder-method="reorderItems">
                @foreach ($checklist_items as $key => $item)
                    <x-ui.sortable-row :item-key="$key">
                        <span class="w-5 text-right text-[10px] tabular-nums text-neutral-600">{{ $loop->index + 1 }}.</span>
                        <input wire:model="checklist_items.{{ $key }}.label"
                               type="text"
                               placeholder="{{ __('Item') }}"
                               class="flex-1 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <label class="flex items-center gap-1 text-[11px] text-neutral-400" title="{{ __('Active — shows on the today page') }}">
                            <input type="checkbox" wire:model="checklist_items.{{ $key }}.active"
                                   class="rounded border-neutral-700 bg-neutral-950">
                            <span>{{ __('on') }}</span>
                        </label>
                        <button type="button" wire:click="removeItem('{{ $key }}')"
                                title="{{ __('Remove') }}" aria-label="{{ __('Remove item') }}"
                                class="rounded p-1 text-rose-400 hover:bg-rose-900/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">×</button>
                    </x-ui.sortable-row>
                @endforeach
            </x-ui.sortable-list>
        @endif
        <button type="button" wire:click="addItem"
                class="mt-2 rounded-md border border-dashed border-neutral-700 px-3 py-1.5 text-xs text-neutral-300 hover:border-neutral-500 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            + {{ __('Add item') }}
        </button>
        @error('checklist_items')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </fieldset>

    <div>
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="checklist_active" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            <span>{{ __('Active') }}</span>
        </label>
        <p class="mt-1 text-[11px] text-neutral-500">
            {{ __('Recurring templates (daily / weekly / custom) surface on Habits with a streak. One-off templates surface on Checklists.') }}
        </p>
    </div>

    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
