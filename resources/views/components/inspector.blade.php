<?php

use App\Exceptions\PeriodLockedException;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\BodyMeasurement;
use App\Models\BudgetCap;
use App\Models\CategoryRule;
use App\Models\ChecklistTemplate;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Decision;
use App\Models\Document;
use App\Models\Domain;
use App\Models\FoodEntry;
use App\Models\Goal;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Listing;
use App\Models\Location;
use App\Models\MediaLogEntry;
use App\Models\Meeting;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\OnlineAccount;
use App\Models\Pet;
use App\Models\PetLicense;
use App\Models\PetPreventiveCare;
use App\Models\PhysicalMail;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\TagRule;
use App\Models\Task;
use App\Models\TaxDocument;
use App\Models\TaxEstimatedPayment;
use App\Models\TaxYear;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleServiceLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Inspector shell — drawer chrome only.
 *
 * Every form type renders as an extracted Livewire component
 * (App\Livewire\Inspector\{Type}Form). `childForm()` below maps the
 * current $type to the child component, mount args, and a wire-key
 * discriminator; the template calls @livewire(...) exactly once off
 * that spec. Adding a new inspector type is a one-line edit there
 * (plus the class + blade under App\Livewire\Inspector\). The shell's
 * job is:
 *   1. Manage the drawer's open/close + type/id routing.
 *   2. Pass through a few mount params to the child (source_media_id
 *      for the Mail-inbox OCR flow, subentityParentId for pet sub-rows,
 *      projection_prefill_id for the markPaid → transaction handoff).
 *   3. Host the "link to which bill?" picker the TransactionForm raises
 *      via `inspector-projection-candidates` on multi-hit matches.
 *   4. Fan the Save button to the child via `inspector-save`, close on
 *      `inspector-form-saved`.
 *   5. Delete-record entry point (single place for cross-type deletes).
 *
 * NO per-type form state lives here anymore — the child forms own it.
 */
new class extends Component
{
    public bool $open = false;

    public string $type = '';

    public ?int $id = null;

    /**
     * When true, this inspector instance listens on `subentity-edit-open`
     * instead of `inspector-open`, and renders as a centered modal on
     * top of the primary drawer (instead of replacing it). Used by
     * searchable-select's edit-inspector-type pencil so clicking a
     * picker's edit button inside an open drawer doesn't wipe the
     * parent form's state.
     */
    public bool $asModal = false;

    public ?string $errorMessage = null;

    /** Source Media row used to prefill a new bill/transaction form via OCR extraction. */
    public ?int $source_media_id = null;

    /**
     * Parent FK for sub-entity forms (pet_vaccination / pet_checkup /
     * tax_document / tax_estimated_payment). doOpen() writes it; the
     * matching @case renders the child with this as a mount param.
     */
    public ?int $subentityParentId = null;

    /**
     * When the /habits page launches a new-checklist-template form, this
     * flag pre-checks the "Treat as habit" box so the user doesn't land
     * on an un-checked form after clicking "New habit". Forwarded to
     * ChecklistTemplateForm::mount via CHILD_FORM_EXTRAS.
     */
    public bool $asHabitMode = false;

    /**
     * Set by markPaid() to hand a RecurringProjection's values to the
     * extracted TransactionForm at mount time. Cleared after the child
     * mounts so subsequent openInspector('transaction') calls don't
     * redundantly prefill.
     */
    public ?int $projection_prefill_id = null;

    /**
     * Set when a Transaction save finds 2+ candidate projections to link
     * to. The drawer stays open and renders the picker so the user can
     * pick which bill this paid (or skip the link). Cleared on pick/
     * skip/close.
     *
     * @var array<int, array{id:int, title:string, due_on:string, amount:string}>
     */
    public array $ambiguousCandidates = [];

    public ?int $ambiguousTransactionId = null;

    public function mount(bool $asModal = false): void
    {
        $this->asModal = $asModal;
    }

    #[On('inspector-open')]
    public function openInspector(string $type = '', ?int $id = null, ?int $mediaId = null, bool $asHabit = false, ?int $parentId = null): void
    {
        // Only the primary instance responds to the main open event —
        // the modal instance sits quiet on this channel and only wakes
        // up on `subentity-edit-open`. parentId threads through for
        // "add subtask / add service" flows that originate outside of
        // another inspector — i.e. directly from a list row.
        if ($this->asModal) {
            return;
        }
        $this->doOpen($type, $id, $mediaId, $parentId, $asHabit);
    }

    /**
     * Sub-entity edit: opens this inspector instance (expected to be the
     * modal one rendered in the layout with asModal=true) for the given
     * record WITHOUT touching the primary drawer. Wired from the
     * searchable-select pencil so a user editing e.g. a transaction can
     * fix up the selected contact/category without losing their unsaved
     * transaction state.
     */
    #[On('subentity-edit-open')]
    public function openSubentity(string $type = '', ?int $id = null, ?int $parentId = null): void
    {
        if (! $this->asModal) {
            return;
        }
        $this->doOpen($type, $id, null, $parentId);
    }

    /**
     * Extracted child forms fire this event after they persist. The
     * shell closes the drawer on receipt — separate event name from the
     * general `inspector-saved` (which also fires from subentity modal
     * saves and would otherwise close the primary drawer mid-edit).
     */
    #[On('inspector-form-saved')]
    public function onFormSaved(?string $type = null, ?int $id = null): void
    {
        // When the modal instance hosts an extracted form, its save
        // fires inspector-form-saved. Forward here so searchable-select
        // pickers hear a consistent signal regardless of which path ran.
        if ($this->asModal && $type && $id) {
            $this->dispatch('subentity-edit-saved', type: $type, id: $id);
        }
        if ($this->open) {
            $this->close();
        }
    }

    /**
     * TransactionForm detected multiple candidate RecurringProjections
     * for the just-saved transaction. Swap the drawer body to the picker
     * UI — linkProjection + skipProjectionLink live on this shell and
     * operate on $ambiguousTransactionId + $ambiguousCandidates.
     *
     * @param  array<int, array{id:int, title:string, due_on:string, amount:string}>  $candidates
     */
    #[On('inspector-projection-candidates')]
    public function onProjectionCandidates(int $transactionId, array $candidates): void
    {
        $this->ambiguousTransactionId = $transactionId;
        $this->ambiguousCandidates = $candidates;
    }

    /**
     * Resolve multi-hit ambiguity: link the chosen projection to the
     * just-saved transaction and clear the picker state.
     */
    public function linkProjection(int $projectionId): void
    {
        if (! $this->ambiguousTransactionId) {
            return;
        }
        // Only link projections the matcher proposed — defense against
        // a race where $ambiguousCandidates is stale but the component
        // still receives a click.
        $allowed = array_column($this->ambiguousCandidates, 'id');
        if (! in_array($projectionId, $allowed, true)) {
            return;
        }
        RecurringProjection::where('id', $projectionId)
            ->update([
                'status' => 'matched',
                'matched_transaction_id' => $this->ambiguousTransactionId,
                'matched_at' => now(),
                'unmatched_at' => null,
            ]);
        $this->ambiguousCandidates = [];
        $this->ambiguousTransactionId = null;
        $this->dispatch('inspector-saved');
        $this->close();
    }

    /** Skip the picker — save the transaction without linking. */
    public function skipProjectionLink(): void
    {
        $this->ambiguousCandidates = [];
        $this->ambiguousTransactionId = null;
        $this->dispatch('inspector-saved');
        $this->close();
    }

    #[On('inspector-mark-paid')]
    public function markPaid(int $projectionId): void
    {
        if ($this->asModal) {
            return;
        }
        $this->resetExcept(['open', 'asModal']);

        // TransactionForm reads `projection_prefill_id` on mount and
        // seeds amount/description/account from the projection itself.
        // Blade `key()` on the child depends on this id so the child
        // component re-mounts with the fresh projection context.
        $this->type = 'transaction';
        $this->id = null;
        $this->projection_prefill_id = $projectionId;
        $this->open = true;
        $this->errorMessage = null;

        $this->dispatch('inspector-body-shown');
    }

    private function doOpen(string $type, ?int $id, ?int $mediaId = null, ?int $parentId = null, bool $asHabit = false): void
    {
        $this->resetExcept(['open', 'asModal']);
        $this->type = $type;
        $this->id = $id;
        $this->open = true;
        $this->errorMessage = null;

        // Child form mount() reads $id / $mediaId / $projection_prefill_id
        // and handles its own hydration. Shell just latches the inputs
        // so the @livewire(...) call in the @case switch can pass them
        // to the child as mount params.
        if ($mediaId) {
            $this->source_media_id = $mediaId;
        }
        $this->subentityParentId = $parentId;
        $this->asHabitMode = $asHabit;

        $this->dispatch('inspector-body-shown');
    }

    public function save(): void
    {
        // Every inspector type runs as an extracted child component.
        // Shell fans out `inspector-save`; the matching child form
        // validates + persists + fires `inspector-form-saved` back; the
        // shell's onFormSaved() listener closes the drawer. Transaction-
        // Form additionally fires `inspector-projection-candidates` when
        // ProjectionMatcher returns multi-hit; onProjectionCandidates()
        // stashes them on the shell and the picker UI takes over.
        $this->dispatch('inspector-save');
    }

    public function close(): void
    {
        $this->open = false;
        // Keep the modal-mode flag so the instance stays on its
        // subentity-edit-open channel across opens; resetting it would
        // make the modal instance silently become the primary one on
        // the next event.
        $this->resetExcept(['asModal']);
    }

    public function deleteRecord(): void
    {
        if (! $this->id) {
            return;
        }

        try {
            match ($this->type) {
                'task' => Task::findOrFail($this->id)->delete(),
                'transaction' => Transaction::findOrFail($this->id)->delete(),
                'contact' => Contact::findOrFail($this->id)->delete(),
                'note' => Note::findOrFail($this->id)->delete(),
                'journal_entry' => JournalEntry::findOrFail($this->id)->delete(),
                'physical_mail' => PhysicalMail::findOrFail($this->id)->delete(),
                'bill' => RecurringRule::findOrFail($this->id)->delete(),
                'document' => Document::findOrFail($this->id)->delete(),
                'meeting' => Meeting::findOrFail($this->id)->delete(),
                'project' => Project::findOrFail($this->id)->delete(),
                'contract' => Contract::findOrFail($this->id)->delete(),
                'insurance' => Contract::findOrFail($this->id)->delete(),
                'account' => Account::findOrFail($this->id)->delete(),
                'online_account' => OnlineAccount::findOrFail($this->id)->delete(),
                'domain' => Domain::findOrFail($this->id)->delete(),
                'property' => Property::findOrFail($this->id)->delete(),
                'vehicle' => Vehicle::findOrFail($this->id)->delete(),
                'pet' => Pet::findOrFail($this->id)->delete(),
                'inventory' => InventoryItem::findOrFail($this->id)->delete(),
                'reminder' => Reminder::findOrFail($this->id)->delete(),
                'savings_goal' => SavingsGoal::findOrFail($this->id)->delete(),
                'budget_cap' => BudgetCap::findOrFail($this->id)->delete(),
                'category_rule' => CategoryRule::findOrFail($this->id)->delete(),
                'tag_rule' => TagRule::findOrFail($this->id)->delete(),
                'subscription' => Subscription::findOrFail($this->id)->delete(),
                'checklist_template' => ChecklistTemplate::findOrFail($this->id)->delete(),
                'time_entry' => TimeEntry::findOrFail($this->id)->delete(),
                'tax_year' => TaxYear::findOrFail($this->id)->delete(),
                'tax_document' => TaxDocument::findOrFail($this->id)->delete(),
                'tax_estimated_payment' => TaxEstimatedPayment::findOrFail($this->id)->delete(),
                'meter_reading' => MeterReading::findOrFail($this->id)->delete(),
                'vehicle_service_log' => VehicleServiceLog::findOrFail($this->id)->delete(),
                'media_log_entry' => MediaLogEntry::findOrFail($this->id)->delete(),
                'pet_license' => PetLicense::findOrFail($this->id)->delete(),
                'food_entry' => FoodEntry::findOrFail($this->id)->delete(),
                'decision' => Decision::findOrFail($this->id)->delete(),
                'goal' => Goal::findOrFail($this->id)->delete(),
                'appointment' => Appointment::findOrFail($this->id)->delete(),
                'listing' => Listing::findOrFail($this->id)->delete(),
                'location' => Location::findOrFail($this->id)->delete(),
                'pet_preventive_care' => PetPreventiveCare::findOrFail($this->id)->delete(),
                'body_measurement' => BodyMeasurement::findOrFail($this->id)->delete(),
                default => null,
            };
        } catch (PeriodLockedException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('inspector-saved', type: $this->type);
        $this->close();
    }

    #[Computed]
    public function drawerWidthClass(): string
    {
        return match ($this->type) {
            'transaction', 'bill', 'contract', 'vehicle', 'inventory', 'account' => 'max-w-lg',
            'note', 'journal_entry', 'project' => 'max-w-lg',
            'property', 'insurance', 'online_account' => 'max-w-xl',
            default => 'max-w-md',
        };
    }

    /**
     * Child form extras by type — only the types whose mount() takes
     * more than just `id`. Keyed maps translate to mount args + a key
     * discriminator in the `@livewire()` call below. Unlisted types
     * use the default shape: `['id' => $id]` only.
     *
     * @return array<string, array<string, string>> type => (mountArg => shellPropName)
     */
    private const CHILD_FORM_EXTRAS = [
        'transaction' => ['mediaId' => 'source_media_id', 'projectionId' => 'projection_prefill_id'],
        'bill' => ['mediaId' => 'source_media_id'],
        'pet_vaccination' => ['petId' => 'subentityParentId'],
        'pet_checkup' => ['petId' => 'subentityParentId'],
        'pet_license' => ['petId' => 'subentityParentId'],
        'pet_preventive_care' => ['parentId' => 'subentityParentId'],
        'tax_document' => ['parentId' => 'subentityParentId', 'mediaId' => 'source_media_id'],
        'tax_estimated_payment' => ['parentId' => 'subentityParentId'],
        'meter_reading' => ['parentId' => 'subentityParentId'],
        'vehicle_service_log' => ['parentId' => 'subentityParentId'],
        'task' => ['parentId' => 'subentityParentId'],
        'checklist_template' => ['asHabit' => 'asHabitMode'],
        'listing' => ['parentId' => 'subentityParentId'],
        'location' => ['parentId' => 'subentityParentId'],
    ];

    /**
     * Resolves the current $type to the matching extracted child form's
     *
     * @livewire() wiring: component name, mount args, and the key the
     * shell uses to force a remount when discriminating inputs change
     * (mediaId for OCR, projectionId for mark-paid, subentityParentId
     * for pet sub-rows + tax children).
     *
     * Returns null for `$type === ''` (type picker renders instead) and
     * for any unknown type — the template treats that as "render nothing".
     *
     * @return array{component: string, args: array<string, mixed>, key: string}|null
     */
    #[Computed]
    public function childForm(): ?array
    {
        if ($this->type === '') {
            return null;
        }

        $component = 'inspector.'.str_replace('_', '-', $this->type).'-form';
        $args = ['id' => $this->id];
        $extraKeyParts = [];

        foreach (self::CHILD_FORM_EXTRAS[$this->type] ?? [] as $mountArg => $shellProp) {
            $value = $this->{$shellProp} ?? null;
            $args[$mountArg] = $value;
            $extraKeyParts[] = (string) ($value ?? '0');
        }

        $keyParts = array_merge(
            [$component, $this->id ?? 'new'],
            $extraKeyParts,
            [$this->asModal ? 'm' : 'p'],
        );

        return [
            'component' => $component,
            'args' => $args,
            'key' => implode('-', $keyParts),
        ];
    }

    #[Computed]
    public function heading(): string
    {
        $label = match ($this->type) {
            'task' => __('task'),
            'transaction' => __('transaction'),
            'contact' => __('contact'),
            'note' => __('note'),
            'journal_entry' => __('journal entry'),
            'physical_mail' => __('post'),
            'bill' => __('bill'),
            'document' => __('document'),
            'meeting' => __('meeting'),
            'project' => __('project'),
            'contract' => __('contract'),
            'insurance' => __('insurance policy'),
            'account' => __('account'),
            'online_account' => __('online account'),
            'domain' => __('domain'),
            'property' => __('property'),
            'vehicle' => __('vehicle'),
            'pet' => __('pet'),
            'pet_vaccination' => __('pet vaccination'),
            'pet_checkup' => __('pet checkup'),
            'pet_license' => __('pet license'),
            'inventory' => __('inventory item'),
            'time_entry' => __('time entry'),
            'transfer' => __('transfer'),
            'checklist_template' => __('checklist'),
            'tax_year' => __('tax year'),
            'tax_document' => __('tax document'),
            'tax_estimated_payment' => __('estimated payment'),
            'meter_reading' => __('meter reading'),
            'vehicle_service_log' => __('vehicle service'),
            'media_log_entry' => __('media entry'),
            'food_entry' => __('food entry'),
            'decision' => __('decision'),
            'goal' => __('goal'),
            'listing' => __('listing'),
            'pet_preventive_care' => __('preventive care'),
            'body_measurement' => __('body measurement'),
            'reminder' => __('reminder'),
            'appointment' => __('appointment'),
            'subscription' => __('subscription'),
            'savings_goal' => __('savings goal'),
            'budget_cap' => __('budget'),
            'category_rule' => __('category rule'),
            'tag_rule' => __('tag rule'),
            'location' => __('location'),
            default => '',
        };

        if ($this->type === '') {
            return __('Add');
        }

        return $this->id ? __('Edit :thing', ['thing' => $label]) : __('New :thing', ['thing' => $label]);
    }
};
?>

<div
    x-data="{
        open: @entangle('open').live,
        focusFirst() {
            // The drawer animates in over ~150ms; an input behind display:none
            // can't accept focus. Retry a handful of times until the form is
            // actually in the layout.
            const attempt = (left) => {
                const form = this.$el.querySelector('form');
                const field = form?.querySelector(
                    'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select'
                );
                if (field && field.offsetParent !== null) {
                    field.focus();
                    if (typeof field.select === 'function' && field.type !== 'date' && field.type !== 'datetime-local') {
                        field.select();
                    }
                    return;
                }
                if (left > 0) {
                    setTimeout(() => attempt(left - 1), 50);
                }
            };
            requestAnimationFrame(() => attempt(6));
        },
        init() {
            Livewire.on('inspector-body-shown', () => this.focusFirst());
        },
    }"
    @keydown.escape.window="if (open) { $wire.close() }"
    class="relative"
>
    {{-- Shell — drawer for the primary instance, centered modal for the
         sub-entity-edit variant. The two live on different z-layers so
         the modal stacks on top of an open drawer (common flow: edit a
         transaction's counterparty from inside the transaction drawer). --}}
    <div x-show="open" x-cloak x-transition.opacity
         @click="$wire.close()"
         class="fixed inset-0 {{ $asModal ? 'z-[60] bg-black/70' : 'z-40 bg-black/60' }}"
         aria-hidden="true"></div>

    <aside
        x-show="open"
        x-cloak
        @if ($asModal)
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        @else
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        @endif
        role="dialog"
        aria-modal="true"
        aria-label="{{ $asModal ? __('Sub-record editor') : __('Record inspector') }}"
        @if ($asModal)
            class="fixed left-1/2 top-1/2 z-[70] flex max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-xl border border-neutral-700 bg-neutral-950 shadow-2xl"
        @else
            class="fixed right-0 top-0 z-50 flex h-screen w-full {{ $this->drawerWidthClass }} flex-col overflow-hidden border-l border-neutral-800 bg-neutral-950 shadow-2xl transition-[max-width] duration-150"
        @endif
    >
        <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
            <h2 class="text-sm font-semibold text-neutral-100">{{ $this->heading }}</h2>
            <button type="button" wire:click="close" aria-label="{{ __('Close') }}"
                    class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        @if ($errorMessage)
            <div role="alert" class="m-4 rounded-md border border-rose-800/50 bg-rose-900/20 px-3 py-2 text-xs text-rose-300">
                {{ $errorMessage }}
            </div>
        @endif

        <div class="flex-1 overflow-y-auto px-5 py-4">
            @if ($ambiguousCandidates !== [])
                {{-- Multi-hit disambiguator: after a Transaction save that found
                     2+ candidate projections, the drawer stays open and asks the
                     user to pick which bill this paid (or skip linking). Handled
                     entirely in the drawer — no separate modal. --}}
                <section role="region" aria-label="{{ __('Pick a bill to link') }}" class="space-y-3">
                    <header>
                        <h3 class="text-sm font-semibold text-neutral-100">{{ __('Which bill did this pay?') }}</h3>
                        <p class="mt-1 text-xs text-neutral-400">{{ __('Multiple projected bills match this transaction. Pick one to link them, or skip to leave this transaction standalone.') }}</p>
                    </header>
                    <ul class="space-y-2">
                        @foreach ($ambiguousCandidates as $c)
                            <li>
                                <button type="button" wire:click="linkProjection({{ $c['id'] }})"
                                        class="flex w-full items-center justify-between gap-3 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-left text-sm text-neutral-100 hover:border-emerald-500 hover:bg-emerald-950/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ $c['title'] }}</div>
                                        <div class="text-[11px] text-neutral-500 tabular-nums">{{ __('due :d', ['d' => $c['due_on']]) }}</div>
                                    </div>
                                    <span class="shrink-0 text-sm tabular-nums text-neutral-300">{{ $c['amount'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex justify-end">
                        <button type="button" wire:click="skipProjectionLink"
                                class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Skip — leave unlinked') }}
                        </button>
                    </div>
                </section>
            @else
                {{-- Empty $type means "Quick add" — show the type
                     picker (primary drawer only; the modal instance
                     only wakes on subentity-edit-open, always with a
                     concrete type). Every other $type resolves via
                     $this->childForm to a single @livewire(...) call;
                     adding a new inspector type doesn't require
                     touching this template. --}}
                @if ($type === '')
                    @unless ($asModal)
                        @include('partials.inspector.type-picker')
                    @endunless
                @elseif ($this->childForm)
                    @livewire(
                        $this->childForm['component'],
                        $this->childForm['args'],
                        key($this->childForm['key']),
                    )
                @endif
            @endif
        </div>

        @if ($type !== '' && $ambiguousCandidates === [])
            <footer class="flex items-center justify-between border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <div>
                    @if ($id)
                        <button type="button"
                                wire:click="deleteRecord"
                                wire:confirm="{{ __('Delete this record? This cannot be undone.') }}"
                                class="rounded-md border border-rose-800/40 px-3 py-1.5 text-xs text-rose-300 hover:bg-rose-900/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Delete') }}
                        </button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="close"
                            class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="save"
                            class="rounded-md bg-neutral-100 px-4 py-1.5 text-xs font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span wire:loading.remove wire:target="save">{{ $id ? __('Save') : __('Create') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </footer>
        @endif
    </aside>
</div>
