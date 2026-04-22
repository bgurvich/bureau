<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Contact;
use App\Models\Domain;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Domain form. Uses HasAdminPanel + HasTagList like the
 * other Assets-hub types. Not shown in adminModelMap on the shell
 * (domains is a fresh type, keeping the shell lean); the owner
 * column is `user_id`-equivalent — we don't carry an owner on
 * domains today, so adminOwnerField() returns null and the admin
 * panel just renders Created/Updated timestamps.
 */
class DomainForm extends Component
{
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public string $domain_name = '';

    public string $domain_registrar = '';

    public string $domain_registered_on = '';

    public string $domain_expires_on = '';

    public bool $domain_auto_renew = false;

    public string $domain_nameservers = '';

    public string $domain_annual_cost = '';

    public string $domain_currency = 'USD';

    public ?int $domain_registrant_contact_id = null;

    public string $domain_notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $d = Domain::findOrFail($id);
            $this->domain_name = (string) $d->name;
            $this->domain_registrar = (string) ($d->registrar ?? '');
            $this->domain_registered_on = $d->registered_on ? $d->registered_on->toDateString() : '';
            $this->domain_expires_on = $d->expires_on ? $d->expires_on->toDateString() : '';
            $this->domain_auto_renew = (bool) $d->auto_renew;
            $this->domain_nameservers = (string) ($d->nameservers ?? '');
            $this->domain_annual_cost = $d->annual_cost !== null ? (string) $d->annual_cost : '';
            $this->domain_currency = (string) ($d->currency ?? 'USD');
            $this->domain_registrant_contact_id = $d->registrant_contact_id;
            $this->domain_notes = (string) ($d->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $household = CurrentHousehold::get();
            $this->domain_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'domain_name' => 'required|string|max:255',
            'domain_registrar' => 'nullable|string|max:120',
            'domain_registered_on' => 'nullable|date',
            'domain_expires_on' => 'nullable|date|after_or_equal:domain_registered_on',
            'domain_auto_renew' => 'boolean',
            'domain_nameservers' => 'nullable|string|max:2000',
            'domain_annual_cost' => 'nullable|numeric|min:0',
            'domain_currency' => 'required|string|size:3|alpha',
            'domain_registrant_contact_id' => 'nullable|integer|exists:contacts,id',
            'domain_notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'name' => mb_strtolower(trim($data['domain_name'])),
            'registrar' => $data['domain_registrar'] ?: null,
            'registered_on' => $data['domain_registered_on'] ?: null,
            'expires_on' => $data['domain_expires_on'] ?: null,
            'auto_renew' => (bool) $data['domain_auto_renew'],
            'nameservers' => $data['domain_nameservers'] ?: null,
            'annual_cost' => $data['domain_annual_cost'] !== '' ? (float) $data['domain_annual_cost'] : null,
            'currency' => strtoupper($data['domain_currency']),
            'registrant_contact_id' => $data['domain_registrant_contact_id'] ?: null,
            'notes' => $data['domain_notes'] ?: null,
        ];

        if ($this->id !== null) {
            Domain::findOrFail($this->id)->update($payload);
        } else {
            $this->id = (int) Domain::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'domain', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'domain', id: $this->id);
    }

    /** @return Collection<int, Contact> */
    #[Computed]
    public function contacts(): Collection
    {
        /** @var Collection<int, Contact> $list */
        $list = Contact::orderBy('display_name')->get(['id', 'display_name']);

        return $list;
    }

    protected function adminOwnerClass(): ?string
    {
        return Domain::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Domains are household-shared — no single-user ownership column.
        // Returning null still wires created_at / updated_at through.
        return null;
    }

    public function render(): View
    {
        return view('livewire.inspector.domain-form');
    }
}
