<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCategoryPicker;
use App\Models\Contact;
use App\Models\Transaction;
use App\Support\Birthdays;
use App\Support\Enums;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Contact form. Edits the full contact surface: name + kind
 * + person/org subfields, comma-separated emails/phones, the five
 * address parts (collapsed back to a single-entry addresses JSON),
 * favorite/vendor/customer flags, relationship roles, tax id,
 * birthday + year-known flag, default category (uses
 * WithCategoryPicker for disambiguated picker options), and
 * match_patterns for vendor re-resolver priority.
 *
 * Ships the `backfillCategoryToTransactions` action inline so the
 * "Apply to uncategorised" button stays next to the category picker.
 */
class ContactForm extends Component
{
    use HasAdminPanel;
    use HasTagList;
    use WithCategoryPicker;

    public ?int $id = null;

    public string $kind = 'person';

    public string $display_name = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $organization = '';

    public bool $favorite = false;

    public bool $is_vendor = false;

    public bool $is_customer = false;

    public string $tax_id = '';

    public string $email = '';

    public string $phone = '';

    public string $contact_address_line = '';

    public string $contact_address_city = '';

    public string $contact_address_region = '';

    public string $contact_address_postcode = '';

    public string $contact_address_country = '';

    public string $contact_match_patterns = '';

    public ?int $contact_category_id = null;

    public ?string $contactBackfillMessage = null;

    /** @var array<int, string> */
    public array $contact_roles = [];

    public string $birthday = '';

    public bool $birthday_year_known = true;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $c = Contact::findOrFail($id);
            $this->kind = (string) $c->kind;
            $this->display_name = (string) $c->display_name;
            $this->first_name = (string) ($c->first_name ?? '');
            $this->last_name = (string) ($c->last_name ?? '');
            $this->organization = (string) ($c->organization ?? '');
            $this->favorite = (bool) $c->favorite;
            $this->is_vendor = (bool) $c->is_vendor;
            $this->is_customer = (bool) $c->is_customer;
            $this->tax_id = (string) ($c->tax_id ?? '');
            $emails = $c->emails ?? [];
            $this->email = is_array($emails) ? implode(', ', $emails) : (string) $emails;
            $phones = $c->phones ?? [];
            $this->phone = is_array($phones) ? implode(', ', $phones) : (string) $phones;
            $this->notes = (string) ($c->notes ?? '');
            $this->contact_match_patterns = (string) ($c->match_patterns ?? '');
            $this->contact_category_id = $c->category_id;
            $this->contact_roles = is_array($c->contact_roles) ? array_values($c->contact_roles) : [];
            $this->birthday = $c->birthday ? $c->birthday->toDateString() : '';
            $this->birthday_year_known = $c->birthday ? (int) $c->birthday->year !== Birthdays::YEAR_UNKNOWN : true;

            $addresses = is_array($c->addresses) ? $c->addresses : [];
            $first = is_array($addresses[0] ?? null) ? $addresses[0] : [];
            $this->contact_address_line = (string) ($first['line'] ?? '');
            $this->contact_address_city = (string) ($first['city'] ?? '');
            $this->contact_address_region = (string) ($first['region'] ?? '');
            $this->contact_address_postcode = (string) ($first['postcode'] ?? '');
            $this->contact_address_country = (string) ($first['country'] ?? '');

            $this->loadAdminMeta();
            $this->loadTagList();
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'kind' => ['required', Rule::in(array_keys(Enums::contactKinds()))],
            'display_name' => 'required|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'organization' => 'nullable|string|max:255',
            'favorite' => 'boolean',
            'is_vendor' => 'boolean',
            'is_customer' => 'boolean',
            'tax_id' => 'nullable|string|max:64',
            'email' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:200',
            'contact_address_line' => 'nullable|string|max:255',
            'contact_address_city' => 'nullable|string|max:100',
            'contact_address_region' => 'nullable|string|max:100',
            'contact_address_postcode' => 'nullable|string|max:32',
            'contact_address_country' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'contact_match_patterns' => 'nullable|string|max:5000',
            'contact_category_id' => 'nullable|integer|exists:categories,id',
            'contact_roles' => 'array',
            'contact_roles.*' => ['string', Rule::in(array_keys(Enums::contactRoles()))],
            'birthday' => 'nullable|date',
            'birthday_year_known' => 'boolean',
        ]);

        $addressParts = array_filter([
            'line' => trim((string) ($data['contact_address_line'] ?? '')) ?: null,
            'city' => trim((string) ($data['contact_address_city'] ?? '')) ?: null,
            'region' => trim((string) ($data['contact_address_region'] ?? '')) ?: null,
            'postcode' => trim((string) ($data['contact_address_postcode'] ?? '')) ?: null,
            'country' => trim((string) ($data['contact_address_country'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);
        $addresses = $addressParts !== [] ? [$addressParts] : null;

        $payload = array_filter([
            'kind' => $data['kind'],
            'display_name' => $data['display_name'],
            'first_name' => $data['first_name'] ?: null,
            'last_name' => $data['last_name'] ?: null,
            'organization' => $data['organization'] ?: null,
            'tax_id' => $data['tax_id'] ?: null,
            'emails' => self::splitList($data['email']),
            'phones' => self::splitList($data['phone']),
            'addresses' => $addresses,
            'notes' => $data['notes'] ?: null,
            'match_patterns' => trim((string) ($data['contact_match_patterns'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        $payload['favorite'] = $data['favorite'];
        $payload['is_vendor'] = $data['is_vendor'];
        $payload['is_customer'] = $data['is_customer'];
        $payload['category_id'] = $data['contact_category_id'] ?: null;

        $roles = array_values(array_unique((array) ($data['contact_roles'] ?? [])));
        $payload['contact_roles'] = $roles === [] ? null : $roles;

        $birthdayRaw = trim((string) ($data['birthday'] ?? ''));
        if ($birthdayRaw === '') {
            $payload['birthday'] = null;
        } else {
            $parsed = CarbonImmutable::parse($birthdayRaw);
            $payload['birthday'] = ($data['birthday_year_known'] ?? true)
                ? $parsed->toDateString()
                : $parsed->setDate(Birthdays::YEAR_UNKNOWN, (int) $parsed->month, (int) $parsed->day)->toDateString();
        }

        if ($this->id !== null) {
            Contact::findOrFail($this->id)->update($payload);
        } else {
            $payload['owner_user_id'] = auth()->id();
            $this->id = (int) Contact::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'contact', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'contact', id: $this->id);
    }

    /**
     * Apply the contact's default category to every Transaction linked
     * to this contact that currently has no category_id. Matches the
     * pre-extraction behavior — persists the picked category on the
     * contact first so the picker and the backfilled rows end up
     * consistent.
     */
    public function backfillCategoryToTransactions(): void
    {
        if (! $this->id || ! $this->contact_category_id) {
            return;
        }
        $contactId = (int) $this->id;
        $categoryId = (int) $this->contact_category_id;

        Contact::whereKey($contactId)->update(['category_id' => $categoryId]);

        $n = Transaction::query()
            ->where('counterparty_contact_id', $contactId)
            ->whereNull('category_id')
            ->update(['category_id' => $categoryId]);

        $this->contactBackfillMessage = $n > 0
            ? __(':n transaction(s) categorised.', ['n' => $n])
            : __('No uncategorised transactions matched.');

        // Inspector-saved signals any parent lists to refresh so the
        // backfilled rows re-render with their new category.
        $this->dispatch('inspector-saved', type: 'contact', id: $this->id);
    }

    /** @return array<int, string> */
    private static function splitList(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $raw) ?: [])));
    }

    protected function adminOwnerClass(): ?string
    {
        return Contact::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'owner_user_id';
    }

    protected function defaultCategoryPickerModel(): string
    {
        return 'contact_category_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.contact-form');
    }
}
