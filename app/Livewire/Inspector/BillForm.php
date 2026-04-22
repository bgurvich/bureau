<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCategoryPicker;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\MailMessage;
use App\Models\Media;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Bill form. A "bill" is a RecurringRule with kind=bill —
 * the rule carries the schedule (RRULE + dtstart + due_offset_days),
 * the amount/currency/account, and the counterparty. Recurring flag
 * drives the RRULE (monthly/weekly/yearly with COUNT=1 as the
 * one-off fallback). Optional `mediaId` mount param pre-fills from an
 * OCR-scanned source (Mail inbox flow).
 */
class BillForm extends Component
{
    use HasAdminPanel;
    use HasTagList;
    use WithCategoryPicker;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $bill_title = '';

    public string $amount = '';

    public string $currency = 'USD';

    public string $issued_on = '';

    public string $due_on = '';

    public ?int $account_id = null;

    public ?int $category_id = null;

    public ?int $counterparty_contact_id = null;

    public bool $is_recurring = false;

    public string $frequency = 'monthly';

    public bool $autopay = false;

    public string $bill_until = '';

    public int $bill_lead_days = 7;

    public ?int $source_media_id = null;

    public function mount(?int $id = null, ?int $mediaId = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $r = RecurringRule::findOrFail($id);
            $this->bill_title = (string) $r->title;
            $this->amount = (string) $r->amount;
            $this->currency = $r->currency ?: $householdCurrency;
            $this->account_id = $r->account_id;
            $this->category_id = $r->category_id;
            $this->counterparty_contact_id = $r->counterparty_contact_id;
            $this->issued_on = $r->dtstart ? $r->dtstart->toDateString() : now()->toDateString();
            $this->due_on = CarbonImmutable::parse($this->issued_on)
                ->addDays((int) ($r->due_offset_days ?? 0))->toDateString();
            $this->autopay = (bool) $r->autopay;
            $this->is_recurring = ! str_contains((string) $r->rrule, 'COUNT=1');
            $this->frequency = match (true) {
                str_contains((string) $r->rrule, 'FREQ=MONTHLY') => 'monthly',
                str_contains((string) $r->rrule, 'FREQ=WEEKLY') => 'weekly',
                str_contains((string) $r->rrule, 'FREQ=YEARLY') => 'yearly',
                default => 'monthly',
            };
            $this->bill_until = $r->until ? $r->until->toDateString() : '';
            $this->bill_lead_days = (int) ($r->lead_days ?? 7);
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->currency = $householdCurrency;
            $this->issued_on = now()->toDateString();
            $this->due_on = now()->addDays(14)->toDateString();
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
            'bill_title' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'account_id' => 'required|integer|exists:accounts,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'counterparty_contact_id' => 'nullable|integer|exists:contacts,id',
            'issued_on' => 'required|date',
            'due_on' => 'required|date|after_or_equal:issued_on',
            'is_recurring' => 'boolean',
            'frequency' => ['required', Rule::in(array_keys(Enums::billFrequencies()))],
            'autopay' => 'boolean',
            'bill_until' => 'nullable|date|after:issued_on',
            'bill_lead_days' => 'required|integer|min:0|max:365',
        ]);

        $issued = CarbonImmutable::parse($data['issued_on']);
        $due = CarbonImmutable::parse($data['due_on']);
        $offset = max(0, $issued->diffInDays($due, absolute: false));

        $rrule = $data['is_recurring']
            ? match ($data['frequency']) {
                'monthly' => 'FREQ=MONTHLY;BYMONTHDAY='.(int) $issued->format('d'),
                'weekly' => 'FREQ=WEEKLY;BYDAY='.strtoupper(substr($issued->format('D'), 0, 2)),
                'yearly' => 'FREQ=YEARLY',
                default => 'FREQ=DAILY',
            }
        : 'FREQ=DAILY;COUNT=1';

        $payload = [
            'kind' => 'bill',
            'title' => $data['bill_title'],
            'amount' => (float) $data['amount'],
            'currency' => strtoupper($data['currency']),
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?: null,
            'counterparty_contact_id' => $this->autoFillInterestCounterparty(
                $data['category_id'] ?: null,
                $data['account_id'] ?: null,
                $data['counterparty_contact_id'] ?: null,
            ),
            'rrule' => $rrule,
            'dtstart' => $issued->toDateString(),
            'due_offset_days' => (int) $offset,
            'autopay' => (bool) $data['autopay'],
            'until' => $data['bill_until'] ?: null,
            'lead_days' => (int) $data['bill_lead_days'],
            'active' => true,
        ];

        if ($this->id !== null) {
            RecurringRule::findOrFail($this->id)->update($payload);
        } else {
            $rule = RecurringRule::create($payload);
            $this->id = (int) $rule->id;
            $this->materializeInitialProjection($rule);
            $this->attachSourceMediaTo($rule);
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: 'bill', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'bill', id: $this->id);
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

    /**
     * Interest rows hit a mechanical edge case: the category is
     * interest-paid / interest-earned and the user leaves counterparty
     * blank → auto-fill from the account's vendor. Keeps historic bills
     * linked to the bank that issued the loan/account.
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

    /**
     * Stamp the first RecurringProjection right after creation so the
     * bill shows up on Bills & Income + attention radar immediately.
     */
    private function materializeInitialProjection(RecurringRule $rule): void
    {
        $issued = CarbonImmutable::parse((string) $rule->dtstart);
        $offset = (int) ($rule->due_offset_days ?? 0);
        $due = $issued->addDays($offset);

        RecurringProjection::firstOrCreate(
            ['rule_id' => $rule->id, 'due_on' => $due->toDateString()],
            [
                'issued_on' => $issued->toDateString(),
                'amount' => $rule->amount,
                'currency' => $rule->currency,
                'status' => $due->lt(CarbonImmutable::today()) ? 'overdue' : 'projected',
                'autopay' => (bool) $rule->autopay,
            ]
        );
    }

    /**
     * Attach the OCR-sourced Media the user started from (Mail inbox
     * → Create bill flow) to the new rule and mark it processed so it
     * drops out of the unprocessed inbox.
     */
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

    /**
     * Pre-fill form fields from a Media row's OCR payload — used when
     * the user clicks "Create bill" from a mail inbox attachment. Mirror
     * of the shell's prefillFromMedia for the bill branch; kept local so
     * the child form owns its mount-time hydration.
     */
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
        $issuedOn = is_string($data['issued_on'] ?? null) ? $data['issued_on'] : null;
        $dueOn = is_string($data['due_on'] ?? null) ? $data['due_on'] : null;
        $categoryHint = is_string($data['category_suggestion'] ?? null) ? trim((string) $data['category_suggestion']) : '';
        $currency = is_string($data['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', strtoupper((string) $data['currency']))
            ? strtoupper((string) $data['currency'])
            : null;

        if ($vendor !== '') {
            $this->bill_title = $vendor;
        }
        if ($amount !== null) {
            // Bills record the outflow as a positive magnitude — the
            // ledger direction is encoded by category/account, not sign.
            $this->amount = number_format(abs($amount), 2, '.', '');
        }
        if ($issuedOn) {
            $this->issued_on = $issuedOn;
        }
        if ($dueOn) {
            $this->due_on = $dueOn;
        } elseif ($issuedOn) {
            // No explicit due date on the document — default to issue
            // date so the projection renders instead of blowing up.
            $this->due_on = $issuedOn;
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

        $id = Category::query()
            ->where(function ($q) use ($hint): void {
                $q->whereRaw('LOWER(slug) = ?', [mb_strtolower($hint)])
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($hint)]);
            })
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    protected function adminOwnerClass(): ?string
    {
        return RecurringRule::class;
    }

    protected function adminOwnerField(): ?string
    {
        // RecurringRule has no user_id owner column — bills are
        // household-shared. Admin panel still renders Created/Updated.
        return null;
    }

    protected function defaultCategoryPickerModel(): string
    {
        return 'category_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.bill-form');
    }
}
