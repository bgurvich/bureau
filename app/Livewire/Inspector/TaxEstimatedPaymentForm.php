<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\Account;
use App\Models\TaxEstimatedPayment;
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
 * Quarterly estimated-tax row. `paid_on` flips the projection→paid
 * state; the account_id is optional pre-payment and required-ish after
 * (though unset paid rows are still valid — legacy data). `parentId`
 * at mount seeds the tax_year FK.
 */
class TaxEstimatedPaymentForm extends Component
{
    public ?int $id = null;

    public ?int $tax_year_id = null;

    public string $quarter = 'Q1';

    public string $due_on = '';

    public string $paid_on = '';

    public string $amount = '';

    public string $currency = 'USD';

    public ?int $account_id = null;

    public string $notes = '';

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $p = TaxEstimatedPayment::findOrFail($id);
            $this->tax_year_id = $p->tax_year_id;
            $this->quarter = (string) $p->quarter;
            $this->due_on = $p->due_on ? $p->due_on->toDateString() : '';
            $this->paid_on = $p->paid_on ? $p->paid_on->toDateString() : '';
            $this->amount = $p->amount !== null ? (string) $p->amount : '';
            $this->currency = $p->currency ?: $householdCurrency;
            $this->account_id = $p->account_id;
            $this->notes = (string) ($p->notes ?? '');
        } else {
            $this->tax_year_id = $parentId;
            $this->currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'tax_year_id' => 'required|integer|exists:tax_years,id',
            'quarter' => ['required', Rule::in(array_keys(Enums::taxQuarters()))],
            'due_on' => 'required|date',
            'paid_on' => 'nullable|date',
            'amount' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'tax_year_id' => $data['tax_year_id'],
            'quarter' => $data['quarter'],
            'due_on' => $data['due_on'],
            'paid_on' => $data['paid_on'] ?: null,
            'amount' => $data['amount'] !== '' ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?: null,
            'account_id' => $data['account_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            TaxEstimatedPayment::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) TaxEstimatedPayment::create($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'tax_estimated_payment', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'tax_estimated_payment', id: $this->id);
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

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        /** @var Collection<int, Account> $list */
        $list = Account::orderBy('name')->get(['id', 'name']);

        return $list;
    }

    public function render(): View
    {
        return view('livewire.inspector.tax-estimated-payment-form');
    }
}
