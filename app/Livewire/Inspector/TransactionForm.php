<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasSubjectRefs;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCategoryPicker;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\MailMessage;
use App\Models\Media;
use App\Models\RecurringProjection;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use App\Support\ProjectionMatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Transaction form. Manual ledger insert/edit — runs the
 * ProjectionMatcher on create; when the matcher returns multi-hit, the
 * candidates dispatch upward to the shell, which renders the "Link to
 * which bill?" picker (the shell owns linkProjection / skipProjectionLink
 * + the picker markup). Mount params:
 *   - `id`            — edit an existing Transaction row
 *   - `mediaId`       — pre-fill from a Media OCR payload (receipt flow)
 *   - `projectionId`  — pre-fill from a RecurringProjection (mark-paid)
 */
class TransactionForm extends Component
{
    use HasAdminPanel;
    use HasSubjectRefs;
    use HasTagList;
    use WithCategoryPicker;

    public ?int $id = null;

    public ?int $account_id = null;

    public string $occurred_on = '';

    public string $amount = '';

    public string $currency = 'USD';

    public string $description = '';

    public ?int $category_id = null;

    public ?int $counterparty_contact_id = null;

    public string $status = 'cleared';

    public string $reference_number = '';

    public string $tax_amount = '';

    public string $tax_code = '';

    public string $memo = '';

    public ?int $source_media_id = null;

    public function mount(?int $id = null, ?int $mediaId = null, ?int $projectionId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $t = Transaction::findOrFail($id);
            $this->account_id = $t->account_id;
            $this->occurred_on = $t->occurred_on ? $t->occurred_on->toDateString() : now()->toDateString();
            $this->amount = (string) $t->amount;
            $this->currency = $t->currency ?: $householdCurrency;
            $this->description = (string) ($t->description ?? '');
            $this->category_id = $t->category_id;
            $this->counterparty_contact_id = $t->counterparty_contact_id;
            $this->status = (string) $t->status;
            $this->reference_number = (string) ($t->reference_number ?? '');
            $this->tax_amount = $t->tax_amount !== null ? (string) $t->tax_amount : '';
            $this->tax_code = (string) ($t->tax_code ?? '');
            $this->memo = (string) ($t->memo ?? '');
            $this->subject_refs = $this->subjectRefsFrom($t);
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->currency = $householdCurrency;
            $this->occurred_on = now()->toDateString();
        }

        if ($projectionId !== null) {
            $this->prefillFromProjection($projectionId);
        }

        if ($mediaId) {
            $this->source_media_id = $mediaId;
            $this->prefillFromMedia($mediaId);
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'occurred_on' => 'required|date',
            'amount' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'description' => 'nullable|string|max:500',
            'category_id' => 'nullable|integer|exists:categories,id',
            'counterparty_contact_id' => 'nullable|integer|exists:contacts,id',
            'status' => ['required', Rule::in(array_keys(Enums::transactionStatuses()))],
            'reference_number' => 'nullable|string|max:64',
            'tax_amount' => 'nullable|numeric',
            'tax_code' => 'nullable|string|max:32',
            'memo' => 'nullable|string|max:500',
        ]);

        $data['description'] = $data['description'] ?: null;
        $data['reference_number'] = $data['reference_number'] ?: null;
        $data['tax_amount'] = $data['tax_amount'] !== '' ? $data['tax_amount'] : null;
        $data['tax_code'] = $data['tax_code'] ?: null;
        $data['memo'] = $data['memo'] ?: null;
        $data['counterparty_contact_id'] = $this->autoFillInterestCounterparty(
            $data['category_id'] ?? null,
            $data['account_id'] ?? null,
            $data['counterparty_contact_id'] ?? null,
        );

        /** @var array<int, array{id:int, title:string, due_on:string, amount:string}> $candidates */
        $candidates = [];
        if ($this->id !== null) {
            $transaction = tap(Transaction::findOrFail($this->id))->update($data);
        } else {
            // Manual inspector insert: the user is looking at the row as
            // they save it, so it enters the ledger already reconciled.
            // Only machine-fed imports leave reconciled_at null.
            $data['reconciled_at'] = now();
            $transaction = Transaction::create($data);
            $this->id = (int) $transaction->id;

            $matchResult = ProjectionMatcher::resolve($transaction);
            $this->attachSourceMediaTo($transaction);

            if ($matchResult->isAmbiguous()) {
                $candidates = array_map(
                    fn (RecurringProjection $p): array => [
                        'id' => (int) $p->id,
                        'title' => (string) ($p->rule->title ?? __('Bill')),
                        'due_on' => $p->due_on->toDateString(),
                        'amount' => (string) $p->amount,
                    ],
                    $matchResult->candidates,
                );
            }
        }

        $transaction->syncSubjects($this->parseSubjectRefs($this->subject_refs));

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'transaction', id: $this->id);

        if ($candidates !== []) {
            // Tell the shell to swap to the projection-link picker
            // instead of closing the drawer. Shell owns linkProjection
            // + skipProjectionLink + the picker UI.
            $this->dispatch(
                'inspector-projection-candidates',
                transactionId: $this->id,
                candidates: $candidates,
            );

            return;
        }

        $this->dispatch('inspector-form-saved', type: 'transaction', id: $this->id);
    }

    public function updatedCategoryId(): void
    {
        $this->counterparty_contact_id = $this->autoFillInterestCounterparty(
            $this->category_id,
            $this->account_id,
            $this->counterparty_contact_id,
        );
    }

    public function updatedAccountId(): void
    {
        $this->counterparty_contact_id = $this->autoFillInterestCounterparty(
            $this->category_id,
            $this->account_id,
            $this->counterparty_contact_id,
        );
    }

    public function createCounterparty(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $contact = Contact::create(['kind' => 'org', 'display_name' => $name]);

        $targetKey = $modelKey && property_exists($this, $modelKey)
            ? $modelKey
            : 'counterparty_contact_id';
        $this->{$targetKey} = $contact->id;
        unset($this->contacts);

        $this->dispatch('ss-option-added', model: $targetKey, id: $contact->id, label: $contact->display_name);
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        /** @var Collection<int, Account> $list */
        $list = Account::orderBy('name')->get(['id', 'name', 'currency']);

        return $list;
    }

    /** @return Collection<int, Category> */
    #[Computed]
    public function categories(): Collection
    {
        /** @var Collection<int, Category> $list */
        $list = Category::with('parent:id,name')
            ->orderBy('kind')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'parent_id']);

        return $list;
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
     * Interest-paid / interest-earned transactions on an account always
     * point at the account's counterparty (e.g. interest on an Amex card
     * → Amex). Silently fill it in if the user left it blank.
     */
    private function autoFillInterestCounterparty(?int $categoryId, ?int $accountId, ?int $counterpartyId): ?int
    {
        if ($counterpartyId || ! $categoryId || ! $accountId) {
            return $counterpartyId;
        }

        $slug = Category::where('id', $categoryId)->value('slug');
        if (! in_array($slug, ['interest-paid', 'interest-earned'], true)) {
            return $counterpartyId;
        }

        $account = Account::find($accountId);
        if (! $account) {
            return $counterpartyId;
        }

        return $account->getAttribute('counterparty_contact_id')
            ?? $account->getAttribute('vendor_contact_id');
    }

    private function prefillFromProjection(int $projectionId): void
    {
        $projection = RecurringProjection::with('rule')->find($projectionId);
        if (! $projection) {
            return;
        }
        $rule = $projection->rule;

        $this->amount = (string) $projection->amount;
        $this->currency = $projection->currency ?? 'USD';
        $this->description = (string) ($rule->title ?? '');
        $this->account_id = $rule->account_id;
        $this->category_id = $rule->category_id;
        $this->counterparty_contact_id = $rule->counterparty_contact_id;
        $this->occurred_on = $projection->due_on->toDateString();
        $this->status = 'cleared';
    }

    private function prefillFromMedia(int $mediaId): void
    {
        $media = Media::find($mediaId);
        if (! $media) {
            return;
        }
        $data = $media->ocr_extracted;
        if (! is_array($data) || $data === []) {
            return;
        }

        $vendor = is_string($data['vendor'] ?? null) ? trim((string) $data['vendor']) : '';
        $amount = is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null;
        $taxAmount = is_numeric($data['tax_amount'] ?? null) ? (float) $data['tax_amount'] : null;
        $issuedOn = is_string($data['issued_on'] ?? null) ? $data['issued_on'] : null;
        $categoryHint = is_string($data['category_suggestion'] ?? null) ? trim((string) $data['category_suggestion']) : '';
        $currency = is_string($data['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', strtoupper((string) $data['currency']))
            ? strtoupper((string) $data['currency'])
            : null;

        if ($vendor !== '') {
            $this->description = $vendor;
        }
        if ($amount !== null) {
            // Receipts/bills → outflow → negative in the ledger.
            $this->amount = number_format(-abs($amount), 2, '.', '');
        }
        if ($issuedOn) {
            $this->occurred_on = $issuedOn;
        }
        if ($taxAmount !== null) {
            $this->tax_amount = number_format($taxAmount, 2, '.', '');
        }
        if ($currency) {
            $this->currency = $currency;
        }

        if ($vendor !== '') {
            $contactId = $this->resolveCounterpartyContact($vendor);
            if ($contactId !== null) {
                $this->counterparty_contact_id = $contactId;
            }
        }
        if ($categoryHint !== '') {
            $categoryId = $this->resolveCategoryBySuggestion($categoryHint);
            if ($categoryId !== null) {
                $this->category_id = $categoryId;
            }
        }
    }

    private function resolveCounterpartyContact(string $vendor): ?int
    {
        $vendor = trim($vendor);
        if ($vendor === '') {
            return null;
        }

        $exact = Contact::query()
            ->where(function ($q) use ($vendor): void {
                $q->whereRaw('LOWER(display_name) = ?', [mb_strtolower($vendor)])
                    ->orWhereRaw('LOWER(organization) = ?', [mb_strtolower($vendor)]);
            })
            ->value('id');

        return $exact !== null ? (int) $exact : null;
    }

    private function resolveCategoryBySuggestion(string $hint): ?int
    {
        $hint = trim($hint);
        if ($hint === '') {
            return null;
        }

        // Prefer expense-kind to disambiguate "utilities" between income
        // and expense categories.
        $id = Category::query()
            ->where('kind', 'expense')
            ->where(function ($q) use ($hint): void {
                $q->whereRaw('LOWER(slug) = ?', [mb_strtolower($hint)])
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($hint)]);
            })
            ->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        $id = Category::query()
            ->where(function ($q) use ($hint): void {
                $q->whereRaw('LOWER(slug) = ?', [mb_strtolower($hint)])
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($hint)]);
            })
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function attachSourceMediaTo(Model $record, string $role = 'receipt'): void
    {
        if (! $this->source_media_id) {
            return;
        }
        if (! method_exists($record, 'media')) {
            return;
        }
        $media = Media::find($this->source_media_id);
        if (! $media) {
            return;
        }
        /** @var MorphToMany<Media, Model> $rel */
        $rel = call_user_func([$record, 'media']);
        if (! $rel->where('media.id', $this->source_media_id)->exists()) {
            $rel->attach($this->source_media_id, ['role' => $role]);
        }
        if ($media->processed_at === null) {
            $media->forceFill(['processed_at' => now()])->save();
            MailMessage::cascadeProcessedFromMedia($media->id);
        }
    }

    protected function adminOwnerClass(): ?string
    {
        return Transaction::class;
    }

    protected function adminOwnerField(): ?string
    {
        // Transactions are household-shared — admin panel renders
        // Created/Updated timestamps only.
        return null;
    }

    protected function defaultCategoryPickerModel(): string
    {
        return 'category_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.transaction-form');
    }
}
