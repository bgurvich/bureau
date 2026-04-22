<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * Shared "create a counterparty Contact on the fly" behaviour for
 * inspector forms that bind a searchable-select to a Contact id. The
 * picker's allow-create + create-method="createCounterparty" hooks land
 * here; the writeback target defaults to the form's primary counterparty
 * field but can be overridden per-call via the second searchable-select
 * argument (so pickers bound to vendor/client/buyer/carrier/landlord
 * FKs still get the new option in the right slot).
 *
 * Consumers override `defaultCounterpartyModelKey()` when their primary
 * counterparty isn't named `counterparty_contact_id`.
 */
trait WithCounterpartyPicker
{
    public function createCounterparty(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $contact = Contact::create(['kind' => 'org', 'display_name' => $name]);

        $targetKey = $modelKey && property_exists($this, $modelKey)
            ? $modelKey
            : $this->defaultCounterpartyModelKey();
        $this->{$targetKey} = $contact->id;
        unset($this->contacts);

        $this->dispatch(
            'ss-option-added',
            model: $targetKey,
            id: $contact->id,
            label: $contact->display_name,
        );
    }

    /** @return Collection<int, Contact> */
    #[Computed]
    public function contacts(): Collection
    {
        /** @var Collection<int, Contact> $list */
        $list = Contact::orderBy('display_name')->get(['id', 'display_name']);

        return $list;
    }

    /**
     * Override to rename the field that receives the newly-created
     * contact when a searchable-select doesn't pass an explicit
     * modelKey. Default matches the most common inspector pattern.
     */
    protected function defaultCounterpartyModelKey(): string
    {
        return 'counterparty_contact_id';
    }
}
