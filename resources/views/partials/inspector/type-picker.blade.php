@php
    $pickerGroups = [
        __('Money') => [
            ['transaction', __('Transaction'), 'X'],
            ['bill', __('Bill'), 'B'],
            ['account', __('Account'), 'A'],
            ['subscription', __('Subscription'), 'U'],
            ['budget_cap', __('Budget'), null],
            ['savings_goal', __('Savings goal'), 'G'],
            ['category_rule', __('Category rule'), null],
            ['tag_rule', __('Tag rule'), null],
            ['transfer', __('Transfer'), null],
        ],
        __('Life') => [
            ['task', __('Task'), 'T'],
            ['note', __('Note'), 'N'],
            ['journal_entry', __('Journal'), 'J'],
            ['decision', __('Decision'), 'Z'],
            ['goal', __('Goal'), 'Q'],
            ['media_log_entry', __('Reading / watching'), 'W'],
            ['food_entry', __('Food entry'), 'F'],
            ['contact', __('Contact'), 'C'],
            ['meeting', __('Meeting'), 'M'],
            ['reminder', __('Reminder'), 'R'],
            ['checklist_template', __('Checklist'), 'K'],
        ],
        __('Commitments') => [
            ['contract', __('Contract'), null],
            ['insurance', __('Insurance policy'), null],
        ],
        __('Assets') => [
            ['property', __('Property'), 'H'],
            ['vehicle', __('Vehicle'), 'V'],
            ['inventory', __('Inventory item'), 'I'],
            ['listing', __('Listing'), null],
            ['domain', __('Domain'), null],
            ['meter_reading', __('Meter reading'), 'Y'],
            ['vehicle_service_log', __('Vehicle service'), 'S'],
        ],
        __('Records') => [
            ['document', __('Document'), 'D'],
            ['online_account', __('Online account'), 'O'],
            ['physical_mail', __('Post'), 'P'],
            [null, __('Media / scan'), null],
        ],
        __('Health') => [
            ['appointment', __('Appointment'), 'E'],
            ['body_measurement', __('Body measurement'), null],
            ['pet_license', __('Pet license'), null],
            [null, __('Health provider'), null],
            [null, __('Prescription'), null],
        ],
        __('Time') => [
            ['project', __('Project'), null],
            ['time_entry', __('Time entry'), 'L'],
        ],
        __('Taxes') => [
            ['tax_year', __('Tax year'), null],
            ['tax_document', __('Tax document'), null],
            ['tax_estimated_payment', __('Estimated payment'), null],
        ],
    ];
@endphp
<p class="mb-3 text-xs text-neutral-500">{{ __('What would you like to add?') }}</p>
<div class="space-y-4">
    @foreach ($pickerGroups as $group => $entries)
        <section>
            <h4 class="mb-1.5 text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ $group }}</h4>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($entries as [$value, $label, $key])
                    @if ($value)
                        <button type="button"
                                wire:click="openInspector('{{ $value }}')"
                                class="flex items-center justify-between rounded-md border border-neutral-800 bg-neutral-900/50 px-3 py-2.5 text-left text-sm text-neutral-200 hover:border-neutral-600 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <span>{{ $label }}</span>
                            @if ($key)
                                <kbd class="rounded border border-neutral-700 bg-neutral-950 px-1.5 py-0.5 font-mono text-[10px] text-neutral-500">{{ $key }}</kbd>
                            @endif
                        </button>
                    @else
                        <button type="button" disabled
                                aria-label="{{ $label }} — {{ __('not yet available') }}"
                                class="flex cursor-not-allowed items-center justify-between rounded-md border border-dashed border-neutral-800 bg-neutral-900/20 px-3 py-2.5 text-left text-sm text-neutral-500">
                            <span>{{ $label }}</span>
                            <span class="rounded border border-neutral-800 bg-neutral-950 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-neutral-600">{{ __('soon') }}</span>
                        </button>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
</div>
