<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\TaxDocument;
use App\Models\TaxYear;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A single tax document — W-2, 1099 flavor, K-1, receipt, schedule.
 * `parentId` at mount seeds the tax_year FK so the Tax hub's "+ Add
 * document" button lands on the right year. `mediaId` attaches an
 * OCR-scanned file from the Mail inbox on create.
 */
class TaxDocumentForm extends Component
{
    use WithCounterpartyPicker;

    public ?int $id = null;

    public ?int $tax_year_id = null;

    public string $kind = 'W-2';

    public string $label = '';

    public ?int $from_contact_id = null;

    public string $received_on = '';

    public string $amount = '';

    public string $currency = 'USD';

    public string $notes = '';

    public function mount(?int $id = null, ?int $parentId = null, ?int $mediaId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $d = TaxDocument::findOrFail($id);
            $this->tax_year_id = $d->tax_year_id;
            $this->kind = (string) $d->kind;
            $this->label = (string) ($d->label ?? '');
            $this->from_contact_id = $d->from_contact_id;
            $this->received_on = $d->received_on ? $d->received_on->toDateString() : '';
            $this->amount = $d->amount !== null ? (string) $d->amount : '';
            $this->currency = $d->currency ?: $householdCurrency;
            $this->notes = (string) ($d->notes ?? '');
        } else {
            $this->tax_year_id = $parentId;
            $this->currency = $householdCurrency;
            $this->received_on = now()->toDateString();
        }

        // Source Media attachment is deferred to post-save — we need the
        // TaxDocument row to exist before morphing a pivot onto it.
        if ($mediaId && $id === null) {
            $this->dispatch('tax-document-pending-media', mediaId: $mediaId);
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'tax_year_id' => 'required|integer|exists:tax_years,id',
            'kind' => ['required', Rule::in(array_keys(Enums::taxDocumentKinds()))],
            'label' => 'nullable|string|max:255',
            'from_contact_id' => 'nullable|integer|exists:contacts,id',
            'received_on' => 'nullable|date',
            'amount' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'tax_year_id' => $data['tax_year_id'],
            'kind' => $data['kind'],
            'label' => $data['label'] ?: null,
            'from_contact_id' => $data['from_contact_id'] ?: null,
            'received_on' => $data['received_on'] ?: null,
            'amount' => $data['amount'] !== '' ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            TaxDocument::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) TaxDocument::create($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'tax_document', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'tax_document', id: $this->id);
    }

    /** @return Collection<int, TaxYear> */
    #[Computed]
    public function taxYears(): Collection
    {
        /** @var Collection<int, TaxYear> $list */
        $list = TaxYear::orderByDesc('year')
            ->orderBy('jurisdiction')
            ->get(['id', 'year', 'jurisdiction']);

        return $list;
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'from_contact_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.tax-document-form');
    }
}
