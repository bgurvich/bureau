<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Contact;
use App\Models\Contract;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Contract form (subscription, service agreement, lease,
 * membership, …). Insurance uses a separate InsuranceForm because its
 * schema includes the InsurancePolicy child and carrier/subject links.
 *
 * The counterparty is synced through the Contract↔Contact pivot with
 * `party_role = counterparty`; empty selection detaches all.
 */
class ContractForm extends Component
{
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $type = 'contract';

    public string $contract_kind = 'subscription';

    public string $contract_title = '';

    public string $contract_state = 'active';

    public ?int $contract_counterparty_id = null;

    public string $contract_starts_on = '';

    public string $contract_ends_on = '';

    public string $contract_trial_ends_on = '';

    public bool $contract_auto_renews = false;

    public string $contract_monthly_cost = '';

    public string $contract_monthly_cost_currency = 'USD';

    public ?int $contract_renewal_notice_days = null;

    public string $contract_cancellation_url = '';

    public string $contract_cancellation_email = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $c = Contract::with('contacts')->findOrFail($id);
            $this->contract_kind = (string) $c->kind;
            $this->contract_title = (string) $c->title;
            $this->contract_starts_on = $c->starts_on ? $c->starts_on->toDateString() : '';
            $this->contract_ends_on = $c->ends_on ? $c->ends_on->toDateString() : '';
            $this->contract_trial_ends_on = $c->trial_ends_on ? $c->trial_ends_on->toDateString() : '';
            $this->contract_auto_renews = (bool) $c->auto_renews;
            $this->contract_monthly_cost = $c->monthly_cost_amount !== null ? (string) $c->monthly_cost_amount : '';
            $this->contract_monthly_cost_currency = $c->monthly_cost_currency ?: $householdCurrency;
            $this->contract_state = (string) $c->state;
            $this->contract_counterparty_id = $c->contacts->first()?->id;
            $this->contract_renewal_notice_days = $c->renewal_notice_days !== null ? (int) $c->renewal_notice_days : null;
            $this->contract_cancellation_url = (string) ($c->cancellation_url ?? '');
            $this->contract_cancellation_email = (string) ($c->cancellation_email ?? '');
            $this->notes = (string) ($c->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->contract_monthly_cost_currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'contract_kind' => ['required', Rule::in(array_keys(Enums::contractKinds()))],
            'contract_title' => 'required|string|max:255',
            'contract_starts_on' => 'nullable|date',
            'contract_ends_on' => 'nullable|date|after_or_equal:contract_starts_on',
            'contract_trial_ends_on' => 'nullable|date',
            'contract_auto_renews' => 'boolean',
            'contract_monthly_cost' => 'nullable|numeric',
            'contract_monthly_cost_currency' => 'nullable|string|size:3',
            'contract_state' => ['required', Rule::in(array_keys(Enums::contractStates()))],
            'contract_counterparty_id' => 'nullable|integer|exists:contacts,id',
            'contract_renewal_notice_days' => 'nullable|integer|min:0|max:365',
            'contract_cancellation_url' => 'nullable|string|max:512|url',
            'contract_cancellation_email' => 'nullable|string|max:255|email',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['contract_kind'],
            'title' => $data['contract_title'],
            'starts_on' => $data['contract_starts_on'] ?: null,
            'ends_on' => $data['contract_ends_on'] ?: null,
            'trial_ends_on' => $data['contract_trial_ends_on'] ?: null,
            'auto_renews' => (bool) $data['contract_auto_renews'],
            'monthly_cost_amount' => $data['contract_monthly_cost'] !== '' ? (float) $data['contract_monthly_cost'] : null,
            'monthly_cost_currency' => $data['contract_monthly_cost_currency'] ?: null,
            'state' => $data['contract_state'],
            'renewal_notice_days' => $data['contract_renewal_notice_days'] !== null && $data['contract_renewal_notice_days'] !== ''
                ? (int) $data['contract_renewal_notice_days']
                : null,
            'cancellation_url' => trim((string) $data['contract_cancellation_url']) ?: null,
            'cancellation_email' => trim((string) $data['contract_cancellation_email']) ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            $contract = tap(Contract::findOrFail($this->id))->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $contract = Contract::create($payload);
            $this->id = (int) $contract->id;
        }

        if ($data['contract_counterparty_id']) {
            $contract->contacts()->sync([
                $data['contract_counterparty_id'] => ['party_role' => 'counterparty'],
            ]);
        } else {
            $contract->contacts()->detach();
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'contract', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'contract', id: $this->id);
    }

    protected function adminOwnerClass(): ?string
    {
        return Contract::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'primary_user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'contract_counterparty_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.contract-form');
    }
}
