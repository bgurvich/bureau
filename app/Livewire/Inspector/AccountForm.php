<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Account;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Account form. Holds bank / credit / gift-card / prepaid
 * accounts. Opening balance doubles as face value for gift-card and
 * prepaid types (the partial swaps the label dynamically). Vendor
 * picker supports inline create so users don't have to context-switch
 * to add a new issuer while logging a card.
 */
class AccountForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $account_name = '';

    public string $account_type = 'checking';

    public string $account_currency = 'USD';

    public string $account_opening_balance = '0';

    public string $account_institution = '';

    public ?int $account_vendor_id = null;

    public string $account_expires_on = '';

    public string $account_number_mask = '';

    public string $account_opened_on = '';

    public string $account_closed_on = '';

    public bool $account_is_active = true;

    public bool $account_include_in_net_worth = true;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $a = Account::findOrFail($id);
            $this->account_name = (string) $a->name;
            $this->account_type = (string) $a->type;
            $this->account_currency = $a->currency ?: (CurrentHousehold::get()?->default_currency ?: 'USD');
            $this->account_opening_balance = $a->opening_balance !== null ? (string) $a->opening_balance : '0';
            $this->account_institution = (string) ($a->institution ?? '');
            $this->account_vendor_id = $a->vendor_contact_id;
            $this->account_expires_on = $a->expires_on ? $a->expires_on->toDateString() : '';
            $this->account_is_active = (bool) ($a->is_active ?? true);
            $this->account_include_in_net_worth = (bool) ($a->include_in_net_worth ?? true);
            $this->account_number_mask = (string) ($a->account_number_mask ?? '');
            $this->account_opened_on = $a->opened_on ? $a->opened_on->toDateString() : '';
            $this->account_closed_on = $a->closed_on ? $a->closed_on->toDateString() : '';
            $this->notes = (string) ($a->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $household = CurrentHousehold::get();
            $this->account_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'account_name' => 'required|string|max:255',
            'account_type' => ['required', Rule::in(array_keys(Enums::accountTypes()))],
            'account_currency' => 'required|string|size:3',
            'account_opening_balance' => 'required|numeric',
            'account_institution' => 'nullable|string|max:255',
            'account_vendor_id' => 'nullable|integer|exists:contacts,id',
            'account_expires_on' => 'nullable|date',
            'account_is_active' => 'boolean',
            'account_include_in_net_worth' => 'boolean',
            'account_number_mask' => 'nullable|string|max:32',
            'account_opened_on' => 'nullable|date',
            'account_closed_on' => 'nullable|date|after_or_equal:account_opened_on',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'name' => $data['account_name'],
            'type' => $data['account_type'],
            'currency' => strtoupper($data['account_currency']),
            'opening_balance' => (float) $data['account_opening_balance'],
            'institution' => $data['account_institution'] ?: null,
            'vendor_contact_id' => $data['account_vendor_id'] ?: null,
            'expires_on' => $data['account_expires_on'] ?: null,
            'is_active' => (bool) $data['account_is_active'],
            'include_in_net_worth' => (bool) $data['account_include_in_net_worth'],
            'account_number_mask' => $data['account_number_mask'] ?: null,
            'opened_on' => $data['account_opened_on'] ?: null,
            'closed_on' => $data['account_closed_on'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id !== null) {
            Account::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) Account::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Account::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'account_vendor_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.account-form');
    }
}
