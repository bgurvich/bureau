<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\TaxYear;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tax year container — one row per year-jurisdiction pair. The
 * household_id + year + jurisdiction unique index is the identity;
 * editing just toggles state/filed_on/settlement. Docs + estimated
 * payments attach via their own inspector forms off the detail page.
 */
class TaxYearForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $year = '';

    public string $jurisdiction = 'US-federal';

    public string $filing_status = '';

    /** Who put the numbers on the return — CPA / accountant / spouse / self / friend. */
    public ?int $preparer_contact_id = null;

    /** Ongoing categorization partner. Often distinct from the preparer. */
    public ?int $bookkeeper_contact_id = null;

    public string $state = 'prep';

    public string $filed_on = '';

    public string $settlement_amount = '';

    public string $currency = 'USD';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $t = TaxYear::findOrFail($id);
            $this->year = (string) $t->year;
            $this->jurisdiction = (string) $t->jurisdiction;
            $this->filing_status = (string) ($t->filing_status ?? '');
            $this->preparer_contact_id = $t->preparer_contact_id;
            $this->bookkeeper_contact_id = $t->bookkeeper_contact_id;
            $this->state = (string) $t->state;
            $this->filed_on = $t->filed_on ? $t->filed_on->toDateString() : '';
            $this->settlement_amount = $t->settlement_amount !== null ? (string) $t->settlement_amount : '';
            $this->currency = $t->currency ?: $householdCurrency;
            $this->notes = (string) ($t->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            // Default the new row to last calendar year — the year most
            // users are actively working on during filing season.
            $this->year = (string) ((int) now()->format('Y') - 1);
            $this->currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'year' => [
                'required',
                'integer',
                'between:1900,2100',
                // Household-scoped unique on (year, jurisdiction). The DB
                // enforces it too; this turns the crash into a field
                // error the user can see and fix.
                Rule::unique('tax_years', 'year')
                    ->where('jurisdiction', $this->jurisdiction)
                    ->where('household_id', CurrentHousehold::get()?->id)
                    ->ignore($this->id),
            ],
            'jurisdiction' => 'required|string|max:32',
            'filing_status' => 'nullable|string|max:32',
            'preparer_contact_id' => 'nullable|integer|exists:contacts,id',
            'bookkeeper_contact_id' => 'nullable|integer|exists:contacts,id',
            'state' => ['required', Rule::in(array_keys(Enums::taxYearStates()))],
            'filed_on' => 'nullable|date',
            'settlement_amount' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'year' => (int) $data['year'],
            'jurisdiction' => $data['jurisdiction'],
            'filing_status' => $data['filing_status'] ?: null,
            'preparer_contact_id' => $data['preparer_contact_id'] ?: null,
            'bookkeeper_contact_id' => $data['bookkeeper_contact_id'] ?: null,
            'state' => $data['state'],
            'filed_on' => $data['filed_on'] ?: null,
            'settlement_amount' => $data['settlement_amount'] !== '' ? (float) $data['settlement_amount'] : null,
            'currency' => $data['currency'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            TaxYear::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) TaxYear::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return TaxYear::class;
    }

    protected function defaultCounterpartyModelKey(): string
    {
        // Two contact pickers on the form — which one receives an
        // inline-created contact is passed in the modelKey param from
        // searchable-select's createCounterparty call. This default
        // matches the more common case.
        return 'preparer_contact_id';
    }

    protected function adminOwnerField(): ?string
    {
        // Tax years belong to the household, not a user. Admin panel
        // renders created_at/updated_at only.
        return null;
    }

    public function render(): View
    {
        return view('livewire.inspector.tax-year-form');
    }
}
