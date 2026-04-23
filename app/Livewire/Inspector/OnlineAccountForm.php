<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\OnlineAccount;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted OnlineAccount form. First consumer of HasAdminPanel +
 * HasTagList — admin panel renders Owner + timestamps via the shared
 * `partials/inspector/fields/admin.blade.php` include, tags come in
 * through the shared `fields/tags` partial. The form also hosts two
 * inline contact/contract pickers and a createCounterparty action for
 * the "Recovery contact" inline-create.
 */
class OnlineAccountForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $oa_kind = 'other';

    public string $oa_service_name = '';

    public string $oa_url = '';

    public string $oa_login_email = '';

    public string $oa_username = '';

    public string $oa_mfa_method = 'none';

    public ?int $oa_recovery_contact_id = null;

    public ?int $oa_linked_contract_id = null;

    public string $oa_importance_tier = 'medium';

    public bool $oa_in_case_of_pack = false;

    public string $oa_notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $o = OnlineAccount::where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
                ->findOrFail($id);
            $this->oa_kind = $o->kind ?: 'other';
            $this->oa_service_name = (string) $o->service_name;
            $this->oa_url = (string) ($o->url ?? '');
            $this->oa_login_email = (string) ($o->login_email ?? '');
            $this->oa_username = (string) ($o->username ?? '');
            $this->oa_mfa_method = $o->mfa_method ?: 'none';
            $this->oa_recovery_contact_id = $o->recovery_contact_id;
            $this->oa_linked_contract_id = $o->linked_contract_id;
            $this->oa_importance_tier = $o->importance_tier ?: 'medium';
            $this->oa_in_case_of_pack = (bool) $o->in_case_of_pack;
            $this->oa_notes = (string) ($o->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'oa_kind' => ['required', Rule::in(array_keys(Enums::onlineAccountKinds()))],
            'oa_service_name' => 'required|string|max:255',
            'oa_url' => 'nullable|string|max:500',
            'oa_login_email' => 'nullable|string|max:255',
            'oa_username' => 'nullable|string|max:255',
            'oa_mfa_method' => ['required', Rule::in(array_keys(Enums::mfaMethods()))],
            'oa_recovery_contact_id' => 'nullable|integer|exists:contacts,id',
            'oa_linked_contract_id' => 'nullable|integer|exists:contracts,id',
            'oa_importance_tier' => ['required', Rule::in(array_keys(Enums::importanceTiers()))],
            'oa_in_case_of_pack' => 'boolean',
            'oa_notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['oa_kind'],
            'service_name' => $data['oa_service_name'],
            'url' => $data['oa_url'] ?: null,
            'login_email' => $data['oa_login_email'] ?: null,
            'username' => $data['oa_username'] ?: null,
            'mfa_method' => $data['oa_mfa_method'],
            'recovery_contact_id' => $data['oa_recovery_contact_id'] ?: null,
            'linked_contract_id' => $data['oa_linked_contract_id'] ?: null,
            'importance_tier' => $data['oa_importance_tier'],
            'in_case_of_pack' => (bool) $data['oa_in_case_of_pack'],
            'notes' => $data['oa_notes'] ?: null,
        ];

        if ($this->id !== null) {
            OnlineAccount::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) OnlineAccount::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return Collection<int, Contract> */
    #[Computed]
    public function contracts(): Collection
    {
        /** @var Collection<int, Contract> $list */
        $list = Contract::orderBy('title')->get(['id', 'title']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return OnlineAccount::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'oa_recovery_contact_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.online-account-form');
    }
}
