<?php

use App\Models\Account;
use App\Models\Appointment;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\HealthProvider;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicySubject;
use App\Models\InventoryItem;
use App\Models\Meeting;
use App\Models\OnlineAccount;
use App\Models\PhysicalMail;
use App\Models\Note;
use App\Models\Pet;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use App\Support\Formatting;
use App\Support\ProjectionMatchResult;
use App\Support\ProjectionMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Media;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photoUpload = [];

    public bool $open = false;

    public string $type = '';

    public ?int $id = null;

    /**
     * When true, this inspector instance listens on `subentity-edit-open`
     * instead of `inspector-open`, and renders as a centered modal on
     * top of the primary drawer (instead of replacing it). Used by
     * searchable-select's edit-inspector-type pencil so clicking a
     * picker's edit button inside an open drawer doesn't wipe the
     * parent form's state.
     */
    public bool $asModal = false;

    public function mount(bool $asModal = false): void
    {
        $this->asModal = $asModal;
    }

    // shared-ish fields
    public string $title = '';

    public string $description = '';

    public string $body = '';

    /** Shared free-form notes — used by meeting/contact/account/contract/property/vehicle/inventory/document/project. */
    public string $notes = '';

    /** Shared cross-domain tag input — "#tax-2026 home urgent". Applied to any HasTags record on save. */
    public string $tag_list = '';

    // Shared disposition fields for property/vehicle/inventory.
    public string $disposition = '';

    public string $sale_amount = '';

    public string $sale_currency = 'USD';

    public ?int $buyer_contact_id = null;

    public string $inventory_disposed_on = '';

    public bool $inventory_is_for_sale = false;

    public string $inventory_listing_asking_amount = '';

    public string $inventory_listing_asking_currency = 'USD';

    public string $inventory_listing_platform = '';

    public string $inventory_listing_url = '';

    public string $inventory_listing_posted_at = '';

    // Appointment
    public string $appointment_purpose = '';

    public string $appointment_starts_at = '';

    public string $appointment_ends_at = '';

    public string $appointment_location = '';

    public string $appointment_state = 'scheduled';

    public ?int $appointment_provider_id = null;

    public bool $appointment_self_subject = true;

    // reminder extracted to App\Livewire\Inspector\ReminderForm;
    // the shell hosts the child via @livewire in the render switch.

    // savings_goal extracted to App\Livewire\Inspector\SavingsGoalForm;
    // the shell hosts the child via @livewire in the render switch.

    // time_entry extracted to App\Livewire\Inspector\TimeEntryForm;
    // the shell hosts the child via @livewire in the render switch.

    // transfer extracted to App\Livewire\Inspector\TransferForm;
    // the shell hosts the child via @livewire in the render switch.

    // checklist template (recurring ritual with per-day run tracking)
    public string $checklist_name = '';

    public string $checklist_description = '';

    public string $checklist_time_of_day = 'anytime';

    /** One of: daily | weekdays | weekends | custom */
    public string $checklist_recurrence_mode = 'daily';

    public string $checklist_rrule = '';

    public string $checklist_dtstart = '';

    public string $checklist_paused_until = '';

    public bool $checklist_active = true;

    /**
     * Repeater rows for the items editor. Each row:
     *   ['key' => string, 'id' => ?int, 'label' => string, 'active' => bool]
     * `key` keeps wire:key stable across reorders; `id` is set for rows that
     * already persisted so save-time can update in place.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $checklist_items = [];

    // subscription extracted to App\Livewire\Inspector\SubscriptionForm;
    // the shell hosts the child via @livewire in the render switch.

    // Admin-section meta (read-only display, loaded from the record on edit).
    public ?int $admin_owner_id = null;

    public string $admin_owner_label = '';

    public string $admin_created_at = '';

    public string $admin_updated_at = '';

    // task
    public string $due_at = '';

    public int $priority = 3;

    public string $state = 'open';

    /**
     * Polymorphic subjects for tasks/notes, in user-facing order. Each ref
     * is "{kind}:{id}" — kind is a short alias, id is the subject's PK.
     * Rendered as an ordered chip list + a search-driven add dropdown;
     * persisted to task_subjects / note_subjects.
     *
     * @var array<int, string>
     */
    public array $subject_refs = [];

    /** Free-text search input for the subjects add-dropdown. */
    public string $subject_search = '';

    // transaction
    public ?int $account_id = null;

    public string $occurred_on = '';

    public string $amount = '';

    public string $currency = 'USD';

    public ?int $category_id = null;

    public ?int $counterparty_contact_id = null;

    public string $status = 'cleared';

    public string $reference_number = '';

    public string $tax_amount = '';

    public string $tax_code = '';

    public string $memo = '';

    // contact extracted to App\Livewire\Inspector\ContactForm;
    // the shell hosts the child via @livewire in the render switch.

    // note
    public bool $pinned = false;

    public bool $private = false;

    // bill
    public string $bill_title = '';

    public string $issued_on = '';

    public string $due_on = '';

    public bool $is_recurring = false;

    public string $frequency = 'monthly';

    public bool $autopay = false;

    public string $bill_until = '';

    public int $bill_lead_days = 7;

    // document
    public string $doc_kind = 'passport';

    public string $doc_label = '';

    public string $doc_number = '';

    public string $doc_issuer = '';

    public string $doc_issued_on = '';

    public string $doc_expires_on = '';

    public bool $in_case_of_pack = false;

    // meeting extracted to App\Livewire\Inspector\MeetingForm;
    // the shell hosts the child via @livewire in the render switch.

    // project extracted to App\Livewire\Inspector\ProjectForm;
    // the shell hosts the child via @livewire in the render switch.

    // contract
    public string $contract_kind = 'subscription';

    public string $contract_title = '';

    public string $contract_starts_on = '';

    public string $contract_ends_on = '';

    public string $contract_trial_ends_on = '';

    public bool $contract_auto_renews = false;

    public string $contract_monthly_cost = '';

    public string $contract_monthly_cost_currency = 'USD';

    public string $contract_state = 'active';

    public ?int $contract_counterparty_id = null;

    public ?int $contract_renewal_notice_days = null;

    public string $contract_cancellation_url = '';

    public string $contract_cancellation_email = '';

    // property
    public string $property_kind = 'home';

    public string $property_name = '';

    public string $property_address_line1 = '';

    public string $property_address_city = '';

    public string $property_address_region = '';

    public string $property_address_postcode = '';

    public string $property_acquired_on = '';

    public string $property_purchase_price = '';

    public string $property_purchase_currency = 'USD';

    public string $property_size_value = '';

    public string $property_size_unit = 'sqft';

    public string $property_disposed_on = '';

    // pet — extracted into App\Livewire\Inspector\PetForm. The shell
    // hosts the child based on $type and talks to it through events:
    // parent's Save button dispatches `inspector-save` (via the
    // type=='pet' branch in save()); child fires `inspector-form-saved`
    // back when the write lands, which closes the drawer. Delete
    // still runs from the shell's footer against $this->id.

    // pet_vaccination + pet_checkup moved to
    // App\Livewire\Inspector\PetVaccinationForm and PetCheckupForm.
    // Shell still owns subentity-edit-open routing and passes the
    // parentId (pet.id) down to the child as a mount param — see the
    // @case branches in the render switch.
    //
    // Holds the parentId across the shell → child mount boundary;
    // doOpen() writes it, the case statement reads it.
    public ?int $subentityParentId = null;

    // vehicle
    public string $vehicle_kind = 'car';

    public string $vehicle_make = '';

    public string $vehicle_model = '';

    public string $vehicle_year = '';

    public string $vehicle_color = '';

    public string $vehicle_vin = '';

    public string $vehicle_license_plate = '';

    public string $vehicle_license_jurisdiction = '';

    public string $vehicle_acquired_on = '';

    public string $vehicle_purchase_price = '';

    public string $vehicle_purchase_currency = 'USD';

    public string $vehicle_odometer = '';

    public string $vehicle_odometer_unit = 'mi';

    public string $vehicle_disposed_on = '';

    public string $vehicle_registration_expires_on = '';

    public string $vehicle_registration_fee_amount = '';

    public string $vehicle_registration_fee_currency = 'USD';

    // inventory
    public string $inventory_name = '';

    public int $inventory_quantity = 1;

    public string $inventory_container = '';

    public string $inventory_category = 'other';

    public ?int $inventory_property_id = null;

    public string $inventory_room = '';

    public string $inventory_brand = '';

    public string $inventory_model_number = '';

    public string $inventory_serial_number = '';

    public string $inventory_purchased_on = '';

    public string $inventory_cost_amount = '';

    public string $inventory_cost_currency = 'USD';

    public string $inventory_warranty_expires_on = '';

    public ?int $inventory_vendor_id = null;

    public string $inventory_order_number = '';

    public string $inventory_return_by = '';

    // account extracted to App\Livewire\Inspector\AccountForm;
    // the shell hosts the child via @livewire in the render switch.

    // online_account extracted to App\Livewire\Inspector\OnlineAccountForm;
    // the shell hosts the child via @livewire in the render switch.

    // insurance (contract + policy + one covered subject)
    public string $insurance_title = '';

    public string $insurance_coverage_kind = 'auto';

    public string $insurance_policy_number = '';

    public ?int $insurance_carrier_id = null;

    public string $insurance_starts_on = '';

    public string $insurance_ends_on = '';

    public bool $insurance_auto_renews = false;

    public string $insurance_premium_amount = '';

    public string $insurance_premium_currency = 'USD';

    public string $insurance_premium_cadence = 'monthly';

    public string $insurance_coverage_amount = '';

    public string $insurance_coverage_currency = 'USD';

    public string $insurance_deductible_amount = '';

    public string $insurance_deductible_currency = 'USD';

    public string $insurance_notes = '';

    /** One primary covered subject expressed as "type:id" (e.g. "vehicle:7"). */
    public string $insurance_subject = '';

    public ?string $errorMessage = null;

    /** Source Media row used to prefill a new bill/transaction form via OCR extraction. */
    public ?int $source_media_id = null;

    /**
     * Set when a Transaction save finds 2+ candidate projections to link to.
     * The drawer stays open and renders a picker so the user can pick which
     * bill this paid (or skip the link). Cleared on pick/skip/close.
     *
     * @var array<int, array{id:int, title:string, due_on:string, amount:string}>
     */
    public array $ambiguousCandidates = [];

    public ?int $ambiguousTransactionId = null;

    #[On('inspector-open')]
    public function openInspector(string $type = '', ?int $id = null, ?int $mediaId = null): void
    {
        // Only the primary instance responds to the main open event —
        // the modal instance sits quiet on this channel and only wakes
        // up on `subentity-edit-open`.
        if ($this->asModal) {
            return;
        }
        $this->doOpen($type, $id, $mediaId);
    }

    /**
     * Sub-entity edit: opens this inspector instance (expected to be the
     * modal one rendered in the layout with asModal=true) for the given
     * record WITHOUT touching the primary drawer. Wired from the
     * searchable-select pencil so a user editing e.g. a transaction can
     * fix up the selected contact/category without losing their unsaved
     * transaction state.
     */
    #[On('subentity-edit-open')]
    public function openSubentity(string $type = '', ?int $id = null, ?int $parentId = null): void
    {
        if (! $this->asModal) {
            return;
        }
        $this->doOpen($type, $id, null, $parentId);
    }

    /**
     * Extracted child forms (currently just PetForm) fire this event
     * after they persist. The shell closes the drawer on receipt —
     * separate event name from the general `inspector-saved` (which
     * also fires from subentity modal saves and would otherwise close
     * the primary drawer mid-subentity-edit).
     */
    #[On('inspector-form-saved')]
    public function onFormSaved(?string $type = null, ?int $id = null): void
    {
        // When the modal instance hosts an extracted form, its save fires
        // inspector-form-saved. Legacy subentity saves (still inline) emit
        // subentity-edit-saved directly. Forward here so searchable-select
        // pickers hear a consistent signal regardless of which path ran.
        if ($this->asModal && $type && $id) {
            $this->dispatch('subentity-edit-saved', type: $type, id: $id);
        }
        if ($this->open) {
            $this->close();
        }
    }

    private function doOpen(string $type, ?int $id, ?int $mediaId = null, ?int $parentId = null): void
    {
        $this->resetExcept(['open', 'asModal']);
        $this->type = $type;
        $this->id = $id;
        $this->open = true;
        $this->errorMessage = null;

        if ($id) {
            $this->loadRecord();
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->seedDefaults();
            if ($mediaId) {
                $this->source_media_id = $mediaId;
                $this->prefillFromMedia($mediaId);
            }
            // Pre-seed the parent FK for extracted sub-entity forms; the
            // child's mount() reads this via the `petId` prop passed from
            // the @case('pet_vaccination' | 'pet_checkup') render switch.
            $this->subentityParentId = $parentId;
        }

        $this->dispatch('inspector-body-shown');
    }

    /**
     * Apply extracted OCR fields from a Media row onto the new-record form for
     * bill/transaction types. Reads `Media::ocr_extracted` (written by the
     * ExtractOcrStructure job) and maps vendor / amount / dates / category
     * hint onto the matching form state. Leaves untouched fields at their
     * seeded defaults so the user can override anything.
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
        $taxAmount = is_numeric($data['tax_amount'] ?? null) ? (float) $data['tax_amount'] : null;
        $issuedOn = is_string($data['issued_on'] ?? null) ? $data['issued_on'] : null;
        $dueOn = is_string($data['due_on'] ?? null) ? $data['due_on'] : null;
        $categoryHint = is_string($data['category_suggestion'] ?? null) ? trim((string) $data['category_suggestion']) : '';
        $currency = is_string($data['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', strtoupper((string) $data['currency']))
            ? strtoupper((string) $data['currency'])
            : null;

        if ($this->type === 'bill') {
            if ($vendor !== '') {
                $this->bill_title = $vendor;
            }
            if ($amount !== null) {
                // Bills are an expense direction — store a positive magnitude;
                // the ledger represents the outflow elsewhere.
                $this->amount = number_format(abs($amount), 2, '.', '');
            }
            if ($issuedOn) {
                $this->issued_on = $issuedOn;
            }
            if ($dueOn) {
                $this->due_on = $dueOn;
            } elseif ($issuedOn) {
                // No due date on doc — default to issue date so the projection is sane.
                $this->due_on = $issuedOn;
            }
        } elseif ($this->type === 'transaction') {
            if ($vendor !== '') {
                $this->description = $vendor;
            }
            if ($amount !== null) {
                // Transaction amounts are signed; receipts/bills are outflows → negative.
                $this->amount = number_format(-abs($amount), 2, '.', '');
            }
            if ($issuedOn) {
                $this->occurred_on = $issuedOn;
            }
            if ($taxAmount !== null) {
                $this->tax_amount = number_format($taxAmount, 2, '.', '');
            }
        } else {
            return;
        }

        if ($currency) {
            $this->currency = $currency;
        }

        $contactId = $vendor !== '' ? $this->resolveCounterpartyContact($vendor) : null;
        if ($contactId !== null) {
            $this->counterparty_contact_id = $contactId;
        }

        if ($categoryHint !== '') {
            $categoryId = $this->resolveCategoryBySuggestion($categoryHint, $this->type === 'transaction' ? 'expense' : null);
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

        // Exact case-insensitive match on display_name or organization wins;
        // fall back to a LIKE on display_name so "Pacific Gas & Electric" finds "PG&E"-style entries only if
        // the user explicitly aliased them. Keep the match conservative — wrong counterparty is worse than empty.
        $exact = Contact::query()
            ->where(function ($q) use ($vendor) {
                $q->whereRaw('LOWER(display_name) = ?', [mb_strtolower($vendor)])
                    ->orWhereRaw('LOWER(organization) = ?', [mb_strtolower($vendor)]);
            })
            ->value('id');
        if ($exact) {
            return (int) $exact;
        }

        return null;
    }

    private function resolveCategoryBySuggestion(string $suggestion, ?string $kind): ?int
    {
        $suggestion = mb_strtolower(trim($suggestion));
        if ($suggestion === '') {
            return null;
        }

        $q = Category::query();
        if ($kind) {
            $q->where('kind', $kind);
        }

        return $q->where(function ($sub) use ($suggestion) {
            $sub->whereRaw('LOWER(slug) = ?', [$suggestion])
                ->orWhereRaw('LOWER(name) = ?', [$suggestion])
                ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.$suggestion.'%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$suggestion.'%']);
        })->value('id');
    }

    /**
     * Attach the OCR-source Media row to a freshly created Bill/Transaction so
     * the scan lives on the record going forward. Called from saveBill /
     * saveTransaction after the create path completes.
     */
    /**
     * Map of short kind aliases (used in subject_refs strings and UI groups)
     * to the fully-qualified model class. Single source of truth for which
     * entities can be linked as subjects. Extend here when adding types.
     *
     * @var array<string, class-string>
     */
    public const SUBJECT_KIND_MAP = [
        'vehicle' => Vehicle::class,
        'property' => Property::class,
        'contact' => Contact::class,
        'contract' => Contract::class,
        'inventory' => InventoryItem::class,
        'account' => Account::class,
        'project' => Project::class,
        'document' => Document::class,
        'health_provider' => HealthProvider::class,
        'online_account' => OnlineAccount::class,
        'recurring_rule' => RecurringRule::class,
    ];

    /**
     * Search-on-type matches across every subjectable domain. Only fires
     * when the user has typed at least 2 characters to avoid scanning
     * the entire household inventory on every keystroke. Returns up to 20
     * hits total, spread across kinds (≤ 5 per kind to keep one domain
     * from swamping the list).
     *
     * @return array<int, array{ref: string, label: string, kind_label: string, name: string}>
     */
    #[Computed]
    public function subjectSearchResults(): array
    {
        $q = trim($this->subject_search);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $term = '%'.$q.'%';
        $already = array_flip($this->subject_refs);
        $out = [];

        foreach (self::SUBJECT_KIND_MAP as $kind => $class) {
            $kindLabel = $this->subjectKindLabel($kind);
            $nameCol = $this->subjectNameColumn($class);

            /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
            $rows = $class::query()
                ->where($nameCol, 'like', $term)
                ->orderBy($nameCol)
                ->limit(5)
                ->get();

            foreach ($rows as $row) {
                $ref = $kind.':'.$row->id;
                if (isset($already[$ref])) {
                    continue;
                }
                $name = (string) ($row->{$nameCol} ?? '#'.$row->id);
                $out[] = [
                    'ref' => $ref,
                    'label' => $kindLabel.' · '.$name,
                    'kind_label' => $kindLabel,
                    'name' => $name,
                ];
                if (count($out) >= 20) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Display metadata for the currently-selected subjects, keyed by ref.
     * Used by the chip list renderer. Batches the per-kind queries so the
     * render cost scales with distinct kinds selected, not individual items.
     *
     * @return array<string, array{label: string, kind_label: string}>
     */
    #[Computed]
    public function selectedSubjectsMeta(): array
    {
        if ($this->subject_refs === []) {
            return [];
        }

        $byKind = [];
        foreach ($this->subject_refs as $ref) {
            if (! is_string($ref) || ! str_contains($ref, ':')) {
                continue;
            }
            [$kind, $id] = explode(':', $ref, 2);
            if (! isset(self::SUBJECT_KIND_MAP[$kind]) || ! is_numeric($id)) {
                continue;
            }
            $byKind[$kind][] = (int) $id;
        }

        $out = [];
        foreach ($byKind as $kind => $ids) {
            $class = self::SUBJECT_KIND_MAP[$kind];
            $nameCol = $this->subjectNameColumn($class);
            $kindLabel = $this->subjectKindLabel($kind);

            /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
            $rows = $class::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $row = $rows->get($id);
                $name = $row ? (string) ($row->{$nameCol} ?? '#'.$id) : __('(deleted)');
                $out[$kind.':'.$id] = [
                    'label' => $kindLabel.' · '.$name,
                    'kind_label' => $kindLabel,
                ];
            }
        }

        return $out;
    }

    public function addSubject(string $ref): void
    {
        if (! is_string($ref) || ! str_contains($ref, ':')) {
            return;
        }
        if (in_array($ref, $this->subject_refs, true)) {
            return;
        }
        [$kind] = explode(':', $ref, 2);
        if (! isset(self::SUBJECT_KIND_MAP[$kind])) {
            return;
        }
        $this->subject_refs[] = $ref;
        $this->subject_search = '';
    }

    public function removeSubject(string $ref): void
    {
        $this->subject_refs = array_values(array_diff($this->subject_refs, [$ref]));
    }

    /**
     * Drop-target mover used by the drag-and-drop chip list. Repositions
     * `$ref` to land at index `$newIndex` in the ordered list — the shift
     * happens after the source is removed, so $newIndex is the final slot,
     * not the pre-remove slot.
     */
    public function moveSubjectTo(string $ref, int $newIndex): void
    {
        $idx = array_search($ref, $this->subject_refs, true);
        if ($idx === false) {
            return;
        }
        $item = $this->subject_refs[$idx];
        array_splice($this->subject_refs, $idx, 1);
        $newIndex = max(0, min($newIndex, count($this->subject_refs)));
        array_splice($this->subject_refs, $newIndex, 0, [$item]);
        $this->subject_refs = array_values($this->subject_refs);
    }

    /**
     * Drop-target setter used by the live-reorder sortable list: receives
     * the final DOM-order refs and rebuilds `subject_refs` to match.
     * Unknown refs are ignored; existing refs not in the payload stay at
     * the tail (defensive — the DOM shouldn't diverge, but if it did we'd
     * rather keep a ref than silently drop it).
     *
     * @param  array<int, string>  $orderedRefs
     */
    public function reorderSubjects(array $orderedRefs): void
    {
        if ($this->subject_refs === []) {
            return;
        }

        $existing = array_flip($this->subject_refs);
        $next = [];
        foreach ($orderedRefs as $ref) {
            $r = (string) $ref;
            if (isset($existing[$r])) {
                $next[] = $r;
                unset($existing[$r]);
            }
        }
        foreach (array_keys($existing) as $leftover) {
            $next[] = $leftover;
        }

        $this->subject_refs = $next;
    }

    private function subjectKindLabel(string $kind): string
    {
        return match ($kind) {
            'vehicle' => __('Vehicle'),
            'property' => __('Property'),
            'contact' => __('Contact'),
            'contract' => __('Contract'),
            'inventory' => __('Inventory'),
            'account' => __('Account'),
            'project' => __('Project'),
            'document' => __('Document'),
            'health_provider' => __('Health provider'),
            'online_account' => __('Online account'),
            'recurring_rule' => __('Bill'),
            default => ucfirst($kind),
        };
    }

    /**
     * @param  class-string  $class
     */
    private function subjectNameColumn(string $class): string
    {
        return match ($class) {
            Vehicle::class => 'model',
            Property::class => 'name',
            Contact::class => 'display_name',
            Contract::class => 'title',
            InventoryItem::class => 'name',
            Account::class => 'name',
            Project::class => 'name',
            Document::class => 'label',
            HealthProvider::class => 'name',
            OnlineAccount::class => 'service_name',
            RecurringRule::class => 'title',
            default => 'name',
        };
    }

    /**
     * Turn stored pivot rows back into the "{kind}:{id}" string form the
     * <select multiple> field uses.
     *
     * @return array<int, string>
     */
    private function subjectRefsFrom(\Illuminate\Database\Eloquent\Model $model): array
    {
        if (! method_exists($model, 'subjects')) {
            return [];
        }
        $classToKind = array_flip(self::SUBJECT_KIND_MAP);
        $refs = [];
        foreach ($model->subjects() as $row) {
            $kind = $classToKind[get_class($row)] ?? null;
            if ($kind === null) {
                continue;
            }
            $refs[] = $kind.':'.$row->getKey();
        }

        return $refs;
    }

    /**
     * @param  array<int, string>  $refs
     * @return array<int, array{type: string, id: int}>
     */
    private function parseSubjectRefs(array $refs): array
    {
        $out = [];
        foreach ($refs as $r) {
            if (! is_string($r) || ! str_contains($r, ':')) {
                continue;
            }
            [$kind, $id] = explode(':', $r, 2);
            $class = self::SUBJECT_KIND_MAP[$kind] ?? null;
            if ($class === null || ! is_numeric($id)) {
                continue;
            }
            $out[] = ['type' => $class, 'id' => (int) $id];
        }

        return $out;
    }

    private function attachSourceMediaTo(\Illuminate\Database\Eloquent\Model $record, string $role = 'receipt'): void
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
        /** @var \Illuminate\Database\Eloquent\Relations\MorphToMany $rel */
        $rel = $record->media();
        if (! $rel->where('media.id', $this->source_media_id)->exists()) {
            $rel->attach($this->source_media_id, ['role' => $role]);
        }
        // Auto-mark processed: user has turned the scan into a record, so it
        // no longer belongs in the "Unprocessed" inbox.
        if ($media->processed_at === null) {
            $media->forceFill(['processed_at' => now()])->save();
            \App\Models\MailMessage::cascadeProcessedFromMedia($media->id);
        }
    }

    /**
     * Populate the read-only Admin section (owner / created / updated) from
     * the loaded record. Second DB fetch keeps loadRecord() methods focused
     * on their per-type fields without knowing about meta display.
     *
     * @return array{0: class-string|null, 1: string|null}
     */
    public function adminModelMap(): array
    {
        return match ($this->type) {
            'task' => [Task::class, 'assigned_user_id'],
            'contact' => [Contact::class, 'owner_user_id'],
            'account' => [Account::class, 'user_id'],
            'contract' => [Contract::class, 'primary_user_id'],
            'insurance' => [Contract::class, 'primary_user_id'],
            'property' => [Property::class, 'primary_user_id'],
            'vehicle' => [Vehicle::class, 'primary_user_id'],
            'inventory' => [InventoryItem::class, 'owner_user_id'],
            'document' => [Document::class, 'holder_user_id'],
            'note' => [Note::class, 'user_id'],
            'physical_mail' => [PhysicalMail::class, null],
            'project' => [Project::class, 'user_id'],
            'checklist_template' => [\App\Models\ChecklistTemplate::class, 'user_id'],
            'transaction' => [Transaction::class, null],
            'bill' => [RecurringRule::class, null],
            'appointment' => [Appointment::class, null],
            default => [null, null],
        };
    }

    private function loadAdminMeta(): void
    {
        [$class, $userField] = $this->adminModelMap();
        if (! $class || ! $this->id) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $this->admin_owner_id = $userField ? ($model->{$userField} ?? null) : null;
        $this->admin_owner_label = $this->admin_owner_id
            ? (User::find($this->admin_owner_id)?->name ?? '')
            : '';
        $this->admin_created_at = $model->created_at?->format('Y-m-d H:i') ?? '';
        $this->admin_updated_at = $model->updated_at?->format('Y-m-d H:i') ?? '';
    }

    /**
     * Photos (Media with pivot role=photo) attached to the currently loaded
     * record via the HasMedia trait. Used by any form partial that renders
     * a thumbnail strip — inventory first, others can opt in similarly.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Media>
     */
    public function inspectorPhotos(): \Illuminate\Support\Collection
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return collect();
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return collect();
        }

        return $model->media()
            ->wherePivot('role', 'photo')
            ->orderByPivot('position')
            ->orderBy('media.created_at')
            ->get();
    }

    /**
     * Attach the currently-uploaded photo to the loaded record. Positions
     * it at the end — drag-reorder moves it to a different slot, and the
     * first slot is treated as the cover in drill-down lists.
     */
    public function updatedPhotoUpload(): void
    {
        if (! empty($this->photoUpload)) {
            $this->addPhoto();
        }
    }

    public function addPhoto(): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! method_exists($class, 'media') || empty($this->photoUpload)) {
            return;
        }

        $this->validate(['photoUpload.*' => 'required|image|max:20480']);

        // Create-mode: no record exists yet. Stamp a minimal draft so we have
        // an id to attach against. User's still-unsaved form fields are
        // applied when they eventually click Save, which becomes an update.
        if (! $this->id) {
            $this->ensureDraftForPhoto();
        }

        if (! $this->id) {
            return;
        }

        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $position = (int) $model->media()->wherePivot('role', 'photo')->max('position');

        foreach ($this->photoUpload as $file) {
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            $path = $file->store('inspector-uploads', 'local');

            $media = Media::create([
                'disk' => 'local',
                'path' => $path,
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => $size,
                'captured_at' => now(),
                'ocr_status' => 'skip',
            ]);

            $position++;
            $model->media()->attach($media->id, ['role' => 'photo', 'position' => $position]);
        }

        $this->reset('photoUpload');
    }

    /**
     * Stamp a minimum-viable record for the current type so the user can
     * attach media before completing the form. Mirrors the mobile photo
     * capture pattern: placeholder name + unprocessed, user describes later.
     * Only wired for types we actively want photo-first creation on.
     */
    private function ensureDraftForPhoto(): void
    {
        if ($this->type !== 'inventory') {
            return;
        }

        $name = trim((string) $this->inventory_name) !== ''
            ? (string) $this->inventory_name
            : __('Captured :when', ['when' => now()->format('M j, H:i')]);

        $item = InventoryItem::create([
            'name' => mb_substr($name, 0, 255),
            'quantity' => max(1, (int) ($this->inventory_quantity ?: 1)),
            'category' => $this->inventory_category ?: 'other',
            'owner_user_id' => auth()->id(),
        ]);

        $this->id = $item->id;
        $this->loadAdminMeta();
    }

    public function deletePhoto(int $mediaId): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return;
        }

        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $model->media()->detach($mediaId);
    }

    /**
     * Persist a new order of photos for the loaded record. Incoming ids are
     * the media ids in their new display order (top-left first). Each gets
     * a zero-based pivot position, so "first" = cover everywhere.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderPhotos(array $orderedIds): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return;
        }

        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        foreach (array_values($orderedIds) as $position => $mediaId) {
            $model->media()->updateExistingPivot((int) $mediaId, ['position' => $position]);
        }
    }

    private function loadTagList(): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'tags')) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $class::with('tags:id,name')->find($this->id);
        if (! $model) {
            return;
        }

        $this->tag_list = $model->tags->pluck('name')->implode(' ');
    }

    /**
     * Parse the shared tag input into normalized tag names.
     * Accepts "#tax-2026 #home, urgent" → ['tax-2026', 'home', 'urgent'].
     *
     * @return array<int, string>
     */
    private function parseTagList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $names = [];
        foreach ($parts as $p) {
            $name = trim(ltrim(trim($p), '#'));
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Sync the tag input to the saved record. Missing tags get firstOrCreate'd
     * in the current household. Runs after the type-specific save so $this->id
     * is populated even for freshly-created records.
     */
    private function persistTagList(): void
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'tags')) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $names = $this->parseTagList($this->tag_list);
        $ids = [];
        foreach ($names as $name) {
            $slug = Str::slug($name);
            $tag = Tag::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
            $ids[] = $tag->id;
        }

        $model->tags()->sync($ids);
    }

    public function close(): void
    {
        $this->open = false;
        // Keep the modal-mode flag so the instance stays on its
        // subentity-edit-open channel across opens; resetting it would
        // make the modal instance silently become the primary one on
        // the next event.
        $this->resetExcept(['asModal']);
    }

    private function seedDefaults(): void
    {
        $currency = $this->householdCurrency();

        $this->occurred_on = now()->toDateString();
        $this->due_at = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
        $this->currency = $currency;
        $this->contract_monthly_cost_currency = $currency;
        $this->property_purchase_currency = $currency;
        $this->vehicle_purchase_currency = $currency;
        $this->vehicle_registration_fee_currency = $currency;
        $this->inventory_cost_currency = $currency;
        $this->insurance_premium_currency = $currency;
        $this->insurance_coverage_currency = $currency;
        $this->insurance_deductible_currency = $currency;
        $this->sale_currency = $currency;
        $this->issued_on = now()->toDateString();
        $this->due_on = now()->addDays(14)->toDateString();
        $this->doc_issued_on = now()->toDateString();

        // Physical mail defaults — received today, classified as "other"
        // until the user picks a kind, no processed-at (belongs to the
        // inbox flow).
        $this->pm_received_on = now()->toDateString();
        $this->pm_kind = 'other';
        $this->pm_action_required = false;
        $this->pm_processed_at = '';

        // Checklist defaults — daily routine starting today, two empty rows
        // so the user sees the repeater shape without having to hit "Add
        // item". Stored as a key-keyed map; insertion order = visual order.
        $this->checklist_dtstart = now()->toDateString();
        $this->checklist_recurrence_mode = 'daily';
        $this->checklist_time_of_day = 'anytime';
        $this->checklist_active = true;
        $this->checklist_items = [];
        for ($i = 0; $i < 2; $i++) {
            $key = Str::uuid()->toString();
            $this->checklist_items[$key] = [
                'key' => $key, 'id' => null, 'label' => '', 'active' => true,
            ];
        }
    }

    /**
     * Shared options list for every Inspector form that picks a Category,
     * sorted by name. Computed so the searchable-select component can read
     * it directly and so inline creation (see createCategoryInline) can
     * trigger a re-render by unsetting this computed.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function categoryPickerOptions(): array
    {
        return \App\Models\Category::with('parent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'parent_id'])
            ->mapWithKeys(fn (\App\Models\Category $c) => [$c->id => $c->displayLabel()])
            ->all();
    }

    /**
     * Create a new expense Category on the fly from the searchable-select's
     * "+ Create 'X'" option. Dispatches `ss-option-added` so the Alpine
     * widget pre-selects the new row.
     */
    /**
     * Counterparty (Contact) options keyed by id → display name, sorted.
     * Shared by bill / contract / subscription / transaction inspectors.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function counterpartyPickerOptions(): array
    {
        return \App\Models\Contact::orderBy('display_name')->pluck('display_name', 'id')->all();
    }

    /**
     * Active outflow recurring rules for the subscription inspector's
     * money-side picker. Label includes amount so duplicates (same title,
     * different amount) remain distinguishable.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function recurringOutflowRulePickerOptions(): array
    {
        return \App\Models\RecurringRule::where('active', true)
            ->where('amount', '<=', 0)
            ->orderBy('title')
            ->get(['id', 'title', 'amount', 'currency'])
            ->mapWithKeys(fn ($r) => [
                $r->id => $r->title.' · '.Formatting::money(abs((float) $r->amount), $r->currency ?? 'USD'),
            ])
            ->all();
    }

    /**
     * Non-ended contracts for the subscription inspector's contract picker.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function openContractPickerOptions(): array
    {
        return \App\Models\Contract::whereNotIn('state', ['ended', 'cancelled'])
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    public function createCategoryInline(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $slug = \Illuminate\Support\Str::slug($name);
        $base = $slug === '' ? 'cat-'.bin2hex(random_bytes(3)) : $slug;
        $suffix = 0;
        while (\App\Models\Category::where('slug', $suffix ? "{$base}-{$suffix}" : $base)->exists()) {
            $suffix++;
        }
        $category = \App\Models\Category::create([
            'name' => $name,
            'slug' => $suffix ? "{$base}-{$suffix}" : $base,
            'kind' => 'expense',
        ]);

        unset($this->categoryPickerOptions);

        // Dispatch `ss-option-added` to the specific picker that triggered
        // the inline create. If the client didn't pass a model (older
        // bundles), fall back to notifying every known category picker so
        // at least one of them updates.
        $label = $category->displayLabel(includeKind: true);
        $targets = $modelKey && property_exists($this, $modelKey)
            ? [$modelKey]
            : ['category_id'];
        foreach ($targets as $model) {
            $this->dispatch('ss-option-added', model: $model, id: $category->id, label: $label);
        }

        if (count($targets) === 1) {
            $this->{$targets[0]} = $category->id;
        }
    }

    private function householdCurrency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    private function loadRecord(): void
    {
        match ($this->type) {
            'task' => $this->loadTask(),
            'transaction' => $this->loadTransaction(),
            'note' => $this->loadNote(),
            'physical_mail' => $this->loadPhysicalMail(),
            'bill' => $this->loadBill(),
            'document' => $this->loadDocument(),
            'contract' => $this->loadContract(),
            'insurance' => $this->loadInsurance(),
            'property' => $this->loadProperty(),
            'vehicle' => $this->loadVehicle(),
            // pet / pet_vaccination / pet_checkup all run as extracted
            // class-based components — they load + persist their own
            // state. The shell only manages open/close + parent id.
            'inventory' => $this->loadInventory(),
            'appointment' => $this->loadAppointment(),
            'checklist_template' => $this->loadChecklistTemplate(),
            default => null,
        };
    }

    private function loadTask(): void
    {
        $t = Task::findOrFail($this->id);
        $this->title = $t->title;
        $this->description = $t->description ?? '';
        $this->due_at = $t->due_at ? $t->due_at->format('Y-m-d\TH:i') : '';
        $this->priority = (int) $t->priority;
        $this->state = $t->state;
        $this->subject_refs = $this->subjectRefsFrom($t);
    }

    private function loadTransaction(): void
    {
        $t = Transaction::findOrFail($this->id);
        $this->account_id = $t->account_id;
        $this->occurred_on = $t->occurred_on ? $t->occurred_on->toDateString() : '';
        $this->amount = (string) $t->amount;
        $this->currency = $t->currency;
        $this->description = $t->description ?? '';
        $this->category_id = $t->category_id;
        $this->counterparty_contact_id = $t->counterparty_contact_id;
        $this->status = $t->status;
        $this->reference_number = $t->reference_number ?? '';
        $this->tax_amount = $t->tax_amount !== null ? (string) $t->tax_amount : '';
        $this->tax_code = $t->tax_code ?? '';
        $this->memo = $t->memo ?? '';
        $this->subject_refs = $this->subjectRefsFrom($t);
    }

    // loadContact moved to App\Livewire\Inspector\ContactForm.

    private function loadNote(): void
    {
        $n = Note::findOrFail($this->id);
        $this->title = $n->title ?? '';
        $this->body = $n->body;
        $this->pinned = (bool) $n->pinned;
        $this->private = (bool) $n->private;
        $this->subject_refs = $this->subjectRefsFrom($n);
    }

    // Physical-mail-specific inspector state. Uses the shared $title /
    // $description fields for subject + summary so the Inspector's
    // existing validation + admin partials keep working.
    public string $pm_kind = 'other';

    public string $pm_received_on = '';

    public ?int $pm_sender_id = null;

    public bool $pm_action_required = false;

    public string $pm_processed_at = '';

    private function loadPhysicalMail(): void
    {
        $m = PhysicalMail::findOrFail($this->id);
        $this->title = (string) ($m->subject ?? '');
        $this->description = (string) ($m->summary ?? '');
        $this->pm_kind = (string) ($m->kind ?? 'other');
        $this->pm_received_on = $m->received_on?->toDateString() ?? now()->toDateString();
        $this->pm_sender_id = $m->sender_contact_id;
        $this->pm_action_required = (bool) $m->action_required;
        $this->pm_processed_at = $m->processed_at ? $m->processed_at->format('Y-m-d\TH:i') : '';
    }

    private function loadBill(): void
    {
        $r = RecurringRule::findOrFail($this->id);
        $this->bill_title = $r->title;
        $this->amount = (string) $r->amount;
        $this->currency = $r->currency ?: 'USD';
        $this->account_id = $r->account_id;
        $this->category_id = $r->category_id;
        $this->counterparty_contact_id = $r->counterparty_contact_id;
        $this->issued_on = $r->dtstart?->toDateString() ?? now()->toDateString();
        $this->due_on = CarbonImmutable::parse($this->issued_on)
            ->addDays((int) ($r->due_offset_days ?? 0))->toDateString();
        $this->autopay = (bool) $r->autopay;
        $this->is_recurring = ! str_contains($r->rrule, 'COUNT=1');
        $this->frequency = match (true) {
            str_contains($r->rrule, 'FREQ=MONTHLY') => 'monthly',
            str_contains($r->rrule, 'FREQ=WEEKLY') => 'weekly',
            str_contains($r->rrule, 'FREQ=YEARLY') => 'yearly',
            default => 'monthly',
        };
        $this->bill_until = $r->until?->toDateString() ?? '';
        $this->bill_lead_days = (int) ($r->lead_days ?? 7);
    }

    private function loadDocument(): void
    {
        $d = Document::findOrFail($this->id);
        $this->doc_kind = $d->kind;
        $this->doc_label = $d->label ?? '';
        $this->doc_number = $d->number ?? '';
        $this->doc_issuer = $d->issuer ?? '';
        $this->doc_issued_on = $d->issued_on?->toDateString() ?? '';
        $this->doc_expires_on = $d->expires_on?->toDateString() ?? '';
        $this->in_case_of_pack = (bool) $d->in_case_of_pack;
        $this->notes = $d->notes ?? '';
    }

    // loadProject moved to App\Livewire\Inspector\ProjectForm.

    private function loadContract(): void
    {
        $c = Contract::findOrFail($this->id);
        $this->contract_kind = $c->kind;
        $this->contract_title = $c->title;
        $this->contract_starts_on = $c->starts_on?->toDateString() ?? '';
        $this->contract_ends_on = $c->ends_on?->toDateString() ?? '';
        $this->contract_trial_ends_on = $c->trial_ends_on?->toDateString() ?? '';
        $this->contract_auto_renews = (bool) $c->auto_renews;
        $this->contract_monthly_cost = $c->monthly_cost_amount !== null ? (string) $c->monthly_cost_amount : '';
        $this->contract_monthly_cost_currency = $c->monthly_cost_currency ?: 'USD';
        $this->contract_state = $c->state;
        $this->contract_counterparty_id = $c->contacts()->first()?->id;
        $this->contract_renewal_notice_days = $c->renewal_notice_days !== null ? (int) $c->renewal_notice_days : null;
        $this->contract_cancellation_url = (string) ($c->cancellation_url ?? '');
        $this->contract_cancellation_email = (string) ($c->cancellation_email ?? '');
        $this->notes = $c->notes ?? '';
    }

    // loadAccount moved to App\Livewire\Inspector\AccountForm.

    private function loadInsurance(): void
    {
        $c = Contract::with(['insurancePolicy.subjects', 'contacts'])->findOrFail($this->id);
        $policy = $c->insurancePolicy;

        $this->insurance_title = $c->title;
        $this->insurance_starts_on = $c->starts_on?->toDateString() ?? '';
        $this->insurance_ends_on = $c->ends_on?->toDateString() ?? '';
        $this->insurance_auto_renews = (bool) $c->auto_renews;

        $this->insurance_coverage_kind = $policy?->coverage_kind ?? 'auto';
        $this->insurance_policy_number = $policy?->policy_number ?? '';
        $this->insurance_carrier_id = $policy?->carrier_contact_id;
        $this->insurance_premium_amount = $policy?->premium_amount !== null ? (string) $policy->premium_amount : '';
        $this->insurance_premium_currency = $policy?->premium_currency ?: 'USD';
        $this->insurance_premium_cadence = $policy?->premium_cadence ?: 'monthly';
        $this->insurance_coverage_amount = $policy?->coverage_amount !== null ? (string) $policy->coverage_amount : '';
        $this->insurance_coverage_currency = $policy?->coverage_currency ?: 'USD';
        $this->insurance_deductible_amount = $policy?->deductible_amount !== null ? (string) $policy->deductible_amount : '';
        $this->insurance_deductible_currency = $policy?->deductible_currency ?: 'USD';
        $this->insurance_notes = $policy?->notes ?? '';

        $subject = $policy?->subjects->first();
        $this->insurance_subject = $subject
            ? (string) $this->encodeSubject($subject->subject_type, $subject->subject_id)
            : '';
    }

    private function loadProperty(): void
    {
        $p = Property::findOrFail($this->id);
        $this->property_kind = $p->kind;
        $this->property_name = $p->name;
        $addr = is_array($p->address) ? $p->address : [];
        $this->property_address_line1 = $addr['line1'] ?? '';
        $this->property_address_city = $addr['city'] ?? '';
        $this->property_address_region = $addr['region'] ?? '';
        $this->property_address_postcode = $addr['postcode'] ?? '';
        $this->property_acquired_on = $p->acquired_on?->toDateString() ?? '';
        $this->property_purchase_price = $p->purchase_price !== null ? (string) $p->purchase_price : '';
        $this->property_purchase_currency = $p->purchase_currency ?: 'USD';
        $this->property_size_value = $p->size_value !== null ? (string) $p->size_value : '';
        $this->property_size_unit = $p->size_unit ?: 'sqft';
        $this->property_disposed_on = $p->disposed_on?->toDateString() ?? '';
        $this->disposition = $p->disposition ?? '';
        $this->sale_amount = $p->sale_amount !== null ? (string) $p->sale_amount : '';
        $this->sale_currency = $p->sale_currency ?: $this->householdCurrency();
        $this->buyer_contact_id = $p->buyer_contact_id;
        $this->notes = $p->notes ?? '';
    }

    private function loadVehicle(): void
    {
        $v = Vehicle::findOrFail($this->id);
        $this->vehicle_kind = $v->kind;
        $this->vehicle_make = $v->make ?? '';
        $this->vehicle_model = $v->model ?? '';
        $this->vehicle_year = $v->year !== null ? (string) $v->year : '';
        $this->vehicle_color = $v->color ?? '';
        $this->vehicle_vin = $v->vin ?? '';
        $this->vehicle_license_plate = $v->license_plate ?? '';
        $this->vehicle_license_jurisdiction = $v->license_jurisdiction ?? '';
        $this->vehicle_acquired_on = $v->acquired_on?->toDateString() ?? '';
        $this->vehicle_purchase_price = $v->purchase_price !== null ? (string) $v->purchase_price : '';
        $this->vehicle_purchase_currency = $v->purchase_currency ?: 'USD';
        $this->vehicle_odometer = $v->odometer !== null ? (string) $v->odometer : '';
        $this->vehicle_odometer_unit = $v->odometer_unit ?: 'mi';
        $this->vehicle_registration_expires_on = $v->registration_expires_on?->toDateString() ?? '';
        $this->vehicle_registration_fee_amount = $v->registration_fee_amount !== null ? (string) $v->registration_fee_amount : '';
        $this->vehicle_registration_fee_currency = $v->registration_fee_currency ?: 'USD';
        $this->vehicle_disposed_on = $v->disposed_on?->toDateString() ?? '';
        $this->disposition = $v->disposition ?? '';
        $this->sale_amount = $v->sale_amount !== null ? (string) $v->sale_amount : '';
        $this->sale_currency = $v->sale_currency ?: $this->householdCurrency();
        $this->buyer_contact_id = $v->buyer_contact_id;
        $this->notes = $v->notes ?? '';
    }

    private function loadInventory(): void
    {
        $i = InventoryItem::findOrFail($this->id);
        $this->inventory_name = $i->name;
        $this->inventory_quantity = (int) ($i->quantity ?? 1);
        $this->inventory_category = $i->category ?: 'other';
        $this->inventory_property_id = $i->location_property_id;
        $this->inventory_room = $i->room ?? '';
        $this->inventory_container = $i->container ?? '';
        $this->inventory_brand = $i->brand ?? '';
        $this->inventory_model_number = $i->model_number ?? '';
        $this->inventory_serial_number = $i->serial_number ?? '';
        $this->inventory_purchased_on = $i->purchased_on?->toDateString() ?? '';
        $this->inventory_cost_amount = $i->cost_amount !== null ? (string) $i->cost_amount : '';
        $this->inventory_cost_currency = $i->cost_currency ?: 'USD';
        $this->inventory_warranty_expires_on = $i->warranty_expires_on?->toDateString() ?? '';
        $this->inventory_vendor_id = $i->purchased_from_contact_id;
        $this->inventory_order_number = $i->order_number ?? '';
        $this->inventory_return_by = $i->return_by?->toDateString() ?? '';
        $this->inventory_disposed_on = $i->disposed_on?->toDateString() ?? '';
        $this->disposition = $i->disposition ?? '';
        $this->sale_amount = $i->sale_amount !== null ? (string) $i->sale_amount : '';
        $this->sale_currency = $i->sale_currency ?: $this->householdCurrency();
        $this->buyer_contact_id = $i->buyer_contact_id;
        $this->inventory_is_for_sale = (bool) $i->is_for_sale;
        $this->inventory_listing_asking_amount = $i->listing_asking_amount !== null ? (string) $i->listing_asking_amount : '';
        $this->inventory_listing_asking_currency = $i->listing_asking_currency ?: $this->householdCurrency();
        $this->inventory_listing_platform = $i->listing_platform ?? '';
        $this->inventory_listing_url = $i->listing_url ?? '';
        $this->inventory_listing_posted_at = $i->listing_posted_at?->toDateString() ?? '';
        $this->notes = $i->notes ?? '';
    }

    public function save(): void
    {
        // Extracted form types: the shell's Save button lands here, we
        // fan out an `inspector-save` event, and the matching child
        // component (App\Livewire\Inspector\{Type}Form) validates +
        // persists on its own. The child fires `inspector-form-saved`
        // back and the shell's onFormSaved() listener closes the drawer.
        // Add a type to this array after extracting its child form.
        $extractedTypes = ['pet', 'pet_vaccination', 'pet_checkup', 'time_entry', 'transfer', 'savings_goal', 'budget_cap', 'category_rule', 'tag_rule', 'reminder', 'subscription', 'online_account', 'meeting', 'domain', 'project', 'account', 'contact'];
        if (in_array($this->type, $extractedTypes, true)) {
            $this->dispatch('inspector-save');

            return;
        }

        try {
            match ($this->type) {
                'task' => $this->saveTask(),
                'transaction' => $this->saveTransaction(),
                'note' => $this->saveNote(),
                'physical_mail' => $this->savePhysicalMail(),
                'bill' => $this->saveBill(),
                'document' => $this->saveDocument(),
                'contract' => $this->saveContract(),
                'insurance' => $this->saveInsurance(),
                'property' => $this->saveProperty(),
                'vehicle' => $this->saveVehicle(),
                // pet / pet_vaccination / pet_checkup are extracted; the
                // $extractedTypes early-return above keeps them from
                // reaching this match.
                'inventory' => $this->saveInventory(),
                'appointment' => $this->saveAppointment(),
                'checklist_template' => $this->saveChecklistTemplate(),
                default => null,
            };
        } catch (\App\Exceptions\PeriodLockedException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        // When a Transaction save produced multiple candidate projections,
        // saveTransaction() has stashed them in $ambiguousCandidates. Keep
        // the drawer open so the picker renders instead of silently closing
        // after an un-linked save. linkProjection/skipProjectionLink close
        // the drawer once the user decides.
        if ($this->ambiguousCandidates !== []) {
            return;
        }

        $this->dispatch('inspector-saved', type: $this->type);
        if ($this->asModal) {
            // Picker components (searchable-select) listen for this so they
            // can refresh their option list and re-label the selected row
            // without needing a full parent re-render.
            $this->dispatch('subentity-edit-saved', type: $this->type, id: $this->id);
        }
        $this->close();
    }

    /**
     * Apply the Admin-section Owner picker value to the record, writing to
     * the per-type user FK column via adminModelMap(). Null value means
     * "shared / no owner". Only runs on edits (new records get the owner
     * auto-assigned inside the type-specific save methods).
     */
    private function persistAdminOwner(): void
    {
        if (! $this->id) {
            return;
        }

        [$class, $userField] = $this->adminModelMap();
        if (! $class || ! $userField) {
            return;
        }

        $newOwner = $this->admin_owner_id ?: null;

        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        if ((int) ($model->{$userField} ?? 0) === (int) ($newOwner ?? 0)) {
            return;
        }

        $model->forceFill([$userField => $newOwner])->save();
    }

    private function saveTask(): void
    {
        $data = $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'due_at' => 'nullable|date',
            'priority' => 'required|integer|between:1,5',
            'state' => ['required', Rule::in(array_keys(Enums::taskStates()))],
        ]);

        $data['description'] = $data['description'] ?: null;
        $data['due_at'] = $data['due_at'] ?: null;
        if ($data['state'] === 'done' && $this->id) {
            $data['completed_at'] = now();
        } elseif ($data['state'] !== 'done') {
            $data['completed_at'] = null;
        }

        if ($this->id) {
            $task = Task::findOrFail($this->id);
            $task->update($data);
        } else {
            $data['assigned_user_id'] = auth()->id();
            $task = Task::create($data);
            $this->id = $task->id;
        }
        $task->syncSubjects($this->parseSubjectRefs($this->subject_refs));
    }

    private function saveTransaction(): void
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

        if ($this->id) {
            $transaction = tap(Transaction::findOrFail($this->id))->update($data);
        } else {
            // Manual inspector insert: the user is looking at the row as they
            // save it, so it enters the ledger already reconciled. Only
            // machine-fed imports leave reconciled_at null.
            $data['reconciled_at'] = now();
            $transaction = Transaction::create($data);
            $this->id = $transaction->id;
            // resolve() (vs attempt()) surfaces the ambiguous-match case so we
            // can pause the drawer close + show a picker. Single-hit and miss
            // cases flow straight through (linked via side effect inside).
            $matchResult = ProjectionMatcher::resolve($transaction);
            $this->attachSourceMediaTo($transaction);
            if ($matchResult->isAmbiguous()) {
                $this->ambiguousTransactionId = $transaction->id;
                $this->ambiguousCandidates = array_map(
                    fn (\App\Models\RecurringProjection $p) => [
                        'id' => (int) $p->id,
                        'title' => (string) ($p->rule?->title ?? __('Bill')),
                        'due_on' => $p->due_on?->toDateString() ?? '—',
                        'amount' => (string) $p->amount,
                    ],
                    $matchResult->candidates,
                );
            }
        }
        $transaction->syncSubjects($this->parseSubjectRefs($this->subject_refs));
    }

    /**
     * Resolve multi-hit ambiguity: link the chosen projection to the just-
     * saved transaction and clear the picker state.
     */
    public function linkProjection(int $projectionId): void
    {
        if (! $this->ambiguousTransactionId) {
            return;
        }
        // Only link projections the matcher proposed — defense against a
        // race where $ambiguousCandidates is stale but the component still
        // receives a click.
        $allowed = array_column($this->ambiguousCandidates, 'id');
        if (! in_array($projectionId, $allowed, true)) {
            return;
        }
        \App\Models\RecurringProjection::where('id', $projectionId)
            ->update([
                'status' => 'matched',
                'matched_transaction_id' => $this->ambiguousTransactionId,
                'matched_at' => now(),
                'unmatched_at' => null,
            ]);
        $this->ambiguousCandidates = [];
        $this->ambiguousTransactionId = null;
        $this->dispatch('inspector-saved');
        $this->close();
    }

    /** Skip the picker — save the transaction without linking to any projection. */
    public function skipProjectionLink(): void
    {
        $this->ambiguousCandidates = [];
        $this->ambiguousTransactionId = null;
        $this->dispatch('inspector-saved');
        $this->close();
    }

    /**
     * Real-time feedback: when the user picks the interest category (or swaps
     * accounts while the category is already interest), pre-fill the
     * counterparty from the account so they see the match immediately.
     */
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

    /**
     * Interest-paid / interest-earned transactions on an account always go to
     * the account's counterparty (e.g. interest on an Amex card → Amex).
     * Silently fill it in on save if the user left it blank.
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

        return $account?->counterparty_contact_id ?? $account?->vendor_contact_id ?? null;
    }

    // saveContact + backfillCategoryToTransactions moved to App\Livewire\Inspector\ContactForm.

    private function saveBill(): void
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

        if ($this->id) {
            RecurringRule::findOrFail($this->id)->update($payload);
        } else {
            $rule = RecurringRule::create($payload);
            $this->id = $rule->id;
            $this->materializeInitialProjection($rule);
            $this->attachSourceMediaTo($rule);
        }
    }

    private function materializeInitialProjection(RecurringRule $rule): void
    {
        $issued = CarbonImmutable::parse($rule->dtstart);
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

    private function saveNote(): void
    {
        $data = $this->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string',
            'pinned' => 'boolean',
            'private' => 'boolean',
        ]);

        $payload = [
            'title' => $data['title'] ?: null,
            'body' => $data['body'],
            'pinned' => $data['pinned'],
            'private' => $data['private'],
        ];

        if ($this->id) {
            $note = Note::findOrFail($this->id);
            $note->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $note = Note::create($payload);
            $this->id = $note->id;
        }
        $note->syncSubjects($this->parseSubjectRefs($this->subject_refs));
    }

    private function savePhysicalMail(): void
    {
        $data = $this->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'pm_kind' => ['required', Rule::in(array_keys(Enums::physicalMailKinds()))],
            'pm_received_on' => 'required|date',
            'pm_sender_id' => 'nullable|integer|exists:contacts,id',
            'pm_action_required' => 'boolean',
            'pm_processed_at' => 'nullable|date',
        ]);

        $payload = [
            'subject' => trim((string) $data['title']) ?: null,
            'summary' => trim((string) $data['description']) ?: null,
            'kind' => $data['pm_kind'],
            'received_on' => $data['pm_received_on'],
            'sender_contact_id' => $data['pm_sender_id'] ?: null,
            'action_required' => (bool) $data['pm_action_required'],
            'processed_at' => $data['pm_processed_at']
                ? CarbonImmutable::parse($data['pm_processed_at'])
                : null,
        ];

        if ($this->id) {
            PhysicalMail::findOrFail($this->id)->update($payload);
        } else {
            $m = PhysicalMail::create($payload);
            $this->id = $m->id;
        }
    }

    private function saveDocument(): void
    {
        $data = $this->validate([
            'doc_kind' => ['required', Rule::in(array_keys(Enums::documentKinds()))],
            'doc_label' => 'nullable|string|max:255',
            'doc_number' => 'nullable|string|max:255',
            'doc_issuer' => 'nullable|string|max:255',
            'doc_issued_on' => 'nullable|date',
            'doc_expires_on' => 'nullable|date',
            'in_case_of_pack' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['doc_kind'],
            'label' => $data['doc_label'] ?: null,
            'number' => $data['doc_number'] ?: null,
            'issuer' => $data['doc_issuer'] ?: null,
            'issued_on' => $data['doc_issued_on'] ?: null,
            'expires_on' => $data['doc_expires_on'] ?: null,
            'in_case_of_pack' => (bool) $data['in_case_of_pack'],
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            Document::findOrFail($this->id)->update($payload);
        } else {
            $payload['holder_user_id'] = auth()->id();
            $this->id = Document::create($payload)->id;
        }
    }


    // saveProject moved to App\Livewire\Inspector\ProjectForm.

    private function saveContract(): void
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
            'renewal_notice_days' => $data['contract_renewal_notice_days'] !== null && $data['contract_renewal_notice_days'] !== '' ? (int) $data['contract_renewal_notice_days'] : null,
            'cancellation_url' => trim((string) $data['contract_cancellation_url']) ?: null,
            'cancellation_email' => trim((string) $data['contract_cancellation_email']) ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            $contract = tap(Contract::findOrFail($this->id))->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $contract = Contract::create($payload);
            $this->id = $contract->id;
        }

        if ($data['contract_counterparty_id']) {
            $contract->contacts()->sync([$data['contract_counterparty_id'] => ['party_role' => 'counterparty']]);
        } else {
            $contract->contacts()->detach();
        }
    }

    // saveAccount moved to App\Livewire\Inspector\AccountForm.

    private function saveInsurance(): void
    {
        $data = $this->validate([
            'insurance_title' => 'required|string|max:255',
            'insurance_coverage_kind' => ['required', Rule::in(array_keys(Enums::insuranceCoverageKinds()))],
            'insurance_policy_number' => 'nullable|string|max:128',
            'insurance_carrier_id' => 'nullable|integer|exists:contacts,id',
            'insurance_starts_on' => 'nullable|date',
            'insurance_ends_on' => 'nullable|date|after_or_equal:insurance_starts_on',
            'insurance_auto_renews' => 'boolean',
            'insurance_premium_amount' => 'nullable|numeric',
            'insurance_premium_currency' => 'nullable|string|size:3',
            'insurance_premium_cadence' => ['required', Rule::in(array_keys(Enums::insurancePremiumCadences()))],
            'insurance_coverage_amount' => 'nullable|numeric',
            'insurance_coverage_currency' => 'nullable|string|size:3',
            'insurance_deductible_amount' => 'nullable|numeric',
            'insurance_deductible_currency' => 'nullable|string|size:3',
            'insurance_notes' => 'nullable|string|max:5000',
            'insurance_subject' => 'nullable|string|max:64',
        ]);

        $divisor = Enums::cadenceToMonthlyDivisor($data['insurance_premium_cadence']);
        $monthly = ($divisor !== null && $data['insurance_premium_amount'] !== '')
            ? (float) $data['insurance_premium_amount'] / $divisor
            : null;

        $contractPayload = [
            'kind' => 'insurance',
            'title' => $data['insurance_title'],
            'starts_on' => $data['insurance_starts_on'] ?: null,
            'ends_on' => $data['insurance_ends_on'] ?: null,
            'auto_renews' => (bool) $data['insurance_auto_renews'],
            'monthly_cost_amount' => $monthly !== '' && $monthly !== null ? (float) $monthly : null,
            'monthly_cost_currency' => $data['insurance_premium_currency'] ?: null,
            'state' => 'active',
        ];

        if ($this->id) {
            $contract = tap(Contract::findOrFail($this->id))->update($contractPayload);
        } else {
            $contractPayload['primary_user_id'] = auth()->id();
            $contract = Contract::create($contractPayload);
        }

        if ($data['insurance_carrier_id']) {
            $contract->contacts()->sync([$data['insurance_carrier_id'] => ['party_role' => 'carrier']]);
        } else {
            $contract->contacts()->detach();
        }

        $policyPayload = [
            'contract_id' => $contract->id,
            'coverage_kind' => $data['insurance_coverage_kind'],
            'policy_number' => $data['insurance_policy_number'] ?: null,
            'carrier_contact_id' => $data['insurance_carrier_id'] ?: null,
            'premium_amount' => $data['insurance_premium_amount'] !== '' ? (float) $data['insurance_premium_amount'] : null,
            'premium_currency' => $data['insurance_premium_currency'] ?: null,
            'premium_cadence' => $data['insurance_premium_cadence'],
            'coverage_amount' => $data['insurance_coverage_amount'] !== '' ? (float) $data['insurance_coverage_amount'] : null,
            'coverage_currency' => $data['insurance_coverage_currency'] ?: null,
            'deductible_amount' => $data['insurance_deductible_amount'] !== '' ? (float) $data['insurance_deductible_amount'] : null,
            'deductible_currency' => $data['insurance_deductible_currency'] ?: null,
            'notes' => $data['insurance_notes'] ?: null,
        ];

        $policy = InsurancePolicy::updateOrCreate(
            ['contract_id' => $contract->id],
            $policyPayload,
        );

        $policy->subjects()->delete();
        if ($data['insurance_subject']) {
            [$subjectType, $subjectId] = $this->decodeSubject($data['insurance_subject']);
            if ($subjectType && $subjectId) {
                InsurancePolicySubject::create([
                    'policy_id' => $policy->id,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'role' => 'covered',
                ]);
            }
        }

        $this->id = $contract->id;
    }

    private function encodeSubject(string $class, int $id): string
    {
        $key = match ($class) {
            Vehicle::class => 'vehicle',
            Property::class => 'property',
            \App\Models\User::class => 'user',
            default => 'unknown',
        };

        return $key.':'.$id;
    }

    /** @return array{0:?string,1:?int} */
    private function decodeSubject(string $encoded): array
    {
        $parts = explode(':', $encoded, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        [$key, $id] = $parts;

        $class = match ($key) {
            'vehicle' => Vehicle::class,
            'property' => Property::class,
            'user' => \App\Models\User::class,
            default => null,
        };

        return [$class, ctype_digit($id) ? (int) $id : null];
    }

    private function saveProperty(): void
    {
        $data = $this->validate([
            'property_kind' => ['required', Rule::in(array_keys(Enums::propertyKinds()))],
            'property_name' => 'required|string|max:255',
            'property_address_line1' => 'nullable|string|max:255',
            'property_address_city' => 'nullable|string|max:255',
            'property_address_region' => 'nullable|string|max:64',
            'property_address_postcode' => 'nullable|string|max:32',
            'property_acquired_on' => 'nullable|date',
            'property_purchase_price' => 'nullable|numeric',
            'property_purchase_currency' => 'nullable|string|size:3',
            'property_size_value' => 'nullable|numeric',
            'property_size_unit' => ['nullable', Rule::in(array_keys(Enums::propertySizeUnits()))],
            'property_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $address = array_filter([
            'line1' => $data['property_address_line1'] ?: null,
            'city' => $data['property_address_city'] ?: null,
            'region' => $data['property_address_region'] ?: null,
            'postcode' => $data['property_address_postcode'] ?: null,
        ]);

        $payload = [
            'kind' => $data['property_kind'],
            'name' => $data['property_name'],
            'address' => $address ?: null,
            'acquired_on' => $data['property_acquired_on'] ?: null,
            'purchase_price' => $data['property_purchase_price'] !== '' ? (float) $data['property_purchase_price'] : null,
            'purchase_currency' => $data['property_purchase_currency'] ?: null,
            'size_value' => $data['property_size_value'] !== '' ? (float) $data['property_size_value'] : null,
            'size_unit' => $data['property_size_unit'] ?: null,
            'disposed_on' => $data['property_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            Property::findOrFail($this->id)->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $this->id = Property::create($payload)->id;
        }
    }

    private function saveVehicle(): void
    {
        $data = $this->validate([
            'vehicle_kind' => ['required', Rule::in(array_keys(Enums::vehicleKinds()))],
            'vehicle_make' => 'nullable|string|max:100',
            'vehicle_model' => 'nullable|string|max:100',
            'vehicle_year' => 'nullable|integer|between:1900,2100',
            'vehicle_color' => 'nullable|string|max:64',
            'vehicle_vin' => 'nullable|string|max:17',
            'vehicle_license_plate' => 'nullable|string|max:32',
            'vehicle_license_jurisdiction' => 'nullable|string|max:32',
            'vehicle_acquired_on' => 'nullable|date',
            'vehicle_purchase_price' => 'nullable|numeric',
            'vehicle_purchase_currency' => 'nullable|string|size:3',
            'vehicle_odometer' => 'nullable|integer|min:0',
            'vehicle_odometer_unit' => ['nullable', Rule::in(array_keys(Enums::vehicleOdometerUnits()))],
            'vehicle_registration_expires_on' => 'nullable|date',
            'vehicle_registration_fee_amount' => 'nullable|numeric',
            'vehicle_registration_fee_currency' => 'nullable|string|size:3',
            'vehicle_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['vehicle_kind'],
            'make' => $data['vehicle_make'] ?: null,
            'model' => $data['vehicle_model'] ?: null,
            'year' => $data['vehicle_year'] !== '' ? (int) $data['vehicle_year'] : null,
            'color' => $data['vehicle_color'] ?: null,
            'vin' => $data['vehicle_vin'] ? strtoupper($data['vehicle_vin']) : null,
            'license_plate' => $data['vehicle_license_plate'] ?: null,
            'license_jurisdiction' => $data['vehicle_license_jurisdiction'] ?: null,
            'acquired_on' => $data['vehicle_acquired_on'] ?: null,
            'purchase_price' => $data['vehicle_purchase_price'] !== '' ? (float) $data['vehicle_purchase_price'] : null,
            'purchase_currency' => $data['vehicle_purchase_currency'] ?: null,
            'odometer' => $data['vehicle_odometer'] !== '' ? (int) $data['vehicle_odometer'] : null,
            'odometer_unit' => $data['vehicle_odometer_unit'] ?: 'mi',
            'registration_expires_on' => $data['vehicle_registration_expires_on'] ?: null,
            'registration_fee_amount' => $data['vehicle_registration_fee_amount'] !== '' ? (float) $data['vehicle_registration_fee_amount'] : null,
            'registration_fee_currency' => $data['vehicle_registration_fee_currency'] ?: null,
            'disposed_on' => $data['vehicle_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            Vehicle::findOrFail($this->id)->update($payload);
        } else {
            $payload['primary_user_id'] = auth()->id();
            $this->id = Vehicle::create($payload)->id;
        }
    }

    // loadPet + savePet moved to App\Livewire\Inspector\PetForm as
    // part of the inspector refactor pilot. The shell's save() forks
    // on type=='pet' and dispatches 'inspector-save' so the child
    // handles validation and persistence; an `inspector-form-saved`
    // bounce from the child closes the drawer.

    // loadPetVaccination/savePetVaccination + loadPetCheckup/savePetCheckup
    // moved to App\Livewire\Inspector\PetVaccinationForm and
    // App\Livewire\Inspector\PetCheckupForm. Both are class-based so
    // PHPStan sees the code + tests drive them directly.

    private function saveInventory(): void
    {
        $data = $this->validate([
            'inventory_name' => 'required|string|max:255',
            'inventory_quantity' => 'required|integer|min:1',
            'inventory_category' => ['nullable', Rule::in(array_keys(Enums::inventoryCategories()))],
            'inventory_property_id' => 'nullable|integer|exists:properties,id',
            'inventory_room' => 'nullable|string|max:100',
            'inventory_container' => 'nullable|string|max:100',
            'inventory_brand' => 'nullable|string|max:100',
            'inventory_model_number' => 'nullable|string|max:100',
            'inventory_serial_number' => 'nullable|string|max:100',
            'inventory_purchased_on' => 'nullable|date',
            'inventory_cost_amount' => 'nullable|numeric',
            'inventory_cost_currency' => 'nullable|string|size:3',
            'inventory_warranty_expires_on' => 'nullable|date',
            'inventory_vendor_id' => 'nullable|integer|exists:contacts,id',
            'inventory_order_number' => 'nullable|string|max:128',
            'inventory_return_by' => 'nullable|date',
            'inventory_disposed_on' => 'nullable|date',
            'disposition' => ['nullable', Rule::in(array_keys(Enums::assetDispositions()))],
            'sale_amount' => 'nullable|numeric',
            'sale_currency' => 'nullable|string|size:3',
            'buyer_contact_id' => 'nullable|integer|exists:contacts,id',
            'inventory_is_for_sale' => 'boolean',
            'inventory_listing_asking_amount' => 'nullable|numeric',
            'inventory_listing_asking_currency' => 'nullable|string|size:3',
            'inventory_listing_platform' => ['nullable', Rule::in(array_keys(Enums::inventoryListingPlatforms()))],
            'inventory_listing_url' => 'nullable|url|max:512',
            'inventory_listing_posted_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'name' => $data['inventory_name'],
            'quantity' => max(1, (int) $data['inventory_quantity']),
            'category' => $data['inventory_category'] ?: null,
            'location_property_id' => $data['inventory_property_id'] ?: null,
            'room' => $data['inventory_room'] ?: null,
            'container' => $data['inventory_container'] ?: null,
            'brand' => $data['inventory_brand'] ?: null,
            'model_number' => $data['inventory_model_number'] ?: null,
            'serial_number' => $data['inventory_serial_number'] ?: null,
            'purchased_on' => $data['inventory_purchased_on'] ?: null,
            'cost_amount' => $data['inventory_cost_amount'] !== '' ? (float) $data['inventory_cost_amount'] : null,
            'cost_currency' => $data['inventory_cost_currency'] ?: null,
            'warranty_expires_on' => $data['inventory_warranty_expires_on'] ?: null,
            'purchased_from_contact_id' => $data['inventory_vendor_id'] ?: null,
            'order_number' => $data['inventory_order_number'] ?: null,
            'return_by' => $data['inventory_return_by'] ?: null,
            'processed_at' => now(),
            'disposed_on' => $data['inventory_disposed_on'] ?: null,
            'disposition' => $data['disposition'] ?: null,
            'sale_amount' => $data['sale_amount'] !== '' ? (float) $data['sale_amount'] : null,
            'sale_currency' => $data['sale_currency'] ?: null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?: null,
            'is_for_sale' => (bool) ($data['inventory_is_for_sale'] ?? false),
            'listing_asking_amount' => $data['inventory_listing_asking_amount'] !== '' ? (float) $data['inventory_listing_asking_amount'] : null,
            'listing_asking_currency' => $data['inventory_listing_asking_currency'] ?: null,
            'listing_platform' => $data['inventory_listing_platform'] ?: null,
            'listing_url' => $data['inventory_listing_url'] ?: null,
            'listing_posted_at' => $data['inventory_listing_posted_at'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            InventoryItem::findOrFail($this->id)->update($payload);
        } else {
            $payload['owner_user_id'] = auth()->id();
            $this->id = InventoryItem::create($payload)->id;
        }
    }

    private function loadAppointment(): void
    {
        $a = Appointment::findOrFail($this->id);
        $this->appointment_purpose = $a->purpose ?? '';
        $this->appointment_starts_at = $a->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->appointment_ends_at = $a->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->appointment_location = $a->location ?? '';
        $this->appointment_state = $a->state ?? 'scheduled';
        $this->appointment_provider_id = $a->provider_id;
        // Subject is polymorphic User|Pet. Today we only support self (current user).
        $this->appointment_self_subject = $a->subject_type === \App\Models\User::class
            && $a->subject_id === auth()->id();
        $this->notes = $a->notes ?? '';
    }

    private function saveAppointment(): void
    {
        $data = $this->validate([
            'appointment_purpose' => 'nullable|string|max:255',
            'appointment_starts_at' => 'required|date',
            'appointment_ends_at' => 'nullable|date|after:appointment_starts_at',
            'appointment_location' => 'nullable|string|max:255',
            'appointment_state' => 'nullable|in:scheduled,completed,cancelled,no_show',
            'appointment_provider_id' => 'nullable|integer|exists:health_providers,id',
            'appointment_self_subject' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'purpose' => $data['appointment_purpose'] ?: null,
            'starts_at' => $data['appointment_starts_at'],
            'ends_at' => $data['appointment_ends_at'] ?: null,
            'location' => $data['appointment_location'] ?: null,
            'state' => $data['appointment_state'] ?: 'scheduled',
            'provider_id' => $data['appointment_provider_id'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if (($data['appointment_self_subject'] ?? false)) {
            $payload['subject_type'] = \App\Models\User::class;
            $payload['subject_id'] = auth()->id();
        } else {
            $payload['subject_type'] = null;
            $payload['subject_id'] = null;
        }

        if ($this->id) {
            Appointment::findOrFail($this->id)->update($payload);
        } else {
            $this->id = Appointment::create($payload)->id;
        }
    }

    // reminder load/save moved to App\Livewire\Inspector\ReminderForm.

    // savings_goal load/save moved to App\Livewire\Inspector\SavingsGoalForm.

    // budget_cap extracted to App\Livewire\Inspector\BudgetCapForm.

    // category_rule + tag_rule extracted to App\Livewire\Inspector\{CategoryRuleForm,TagRuleForm}.

    // subscription load/save moved to App\Livewire\Inspector\SubscriptionForm.

    // ── Checklist template ──────────────────────────────────────────────
    //
    // Recurrence is edited as a mode (daily / weekdays / weekends / custom)
    // in the form so the user isn't forced to hand-write an RRULE for the
    // common cases. "custom" reveals the raw string field (RFC-5545 subset
    // supported by App\Support\Rrule).
    private const CHECKLIST_PRESET_RRULES = [
        'daily' => 'FREQ=DAILY',
        'weekdays' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'weekends' => 'FREQ=WEEKLY;BYDAY=SA,SU',
        // One-off: expands to a single occurrence on dtstart. Needed for
        // onboarding checklists + project / move-house / welcome-a-pet
        // one-time lists where a cadence doesn't fit.
        'one_off' => 'FREQ=DAILY;COUNT=1',
    ];

    private function loadChecklistTemplate(): void
    {
        $t = \App\Models\ChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('position')])
            ->findOrFail($this->id);

        $this->checklist_name = $t->name;
        $this->checklist_description = $t->description ?? '';
        $this->checklist_time_of_day = $t->time_of_day ?? 'anytime';
        $this->checklist_rrule = $t->rrule ?? '';
        $this->checklist_dtstart = $t->dtstart?->toDateString() ?? now()->toDateString();
        $this->checklist_paused_until = $t->paused_until?->toDateString() ?? '';
        $this->checklist_active = (bool) $t->active;
        $this->checklist_recurrence_mode = $this->recurrenceModeForRrule($this->checklist_rrule);

        // Repeater rows are stored as a key-keyed associative array so every
        // `wire:model="checklist_items.{key}.label"` binding stays stable
        // across drag-and-drop reorders. PHP arrays preserve insertion order,
        // so the map also carries the visual order — no separate index list.
        $rows = [];
        foreach ($t->items as $i) {
            $key = 'item-'.$i->id;
            $rows[$key] = [
                'key' => $key,
                'id' => (int) $i->id,
                'label' => (string) $i->label,
                'active' => (bool) $i->active,
            ];
        }
        $this->checklist_items = $rows;
    }

    private function saveChecklistTemplate(): void
    {
        $data = $this->validate([
            'checklist_name' => 'required|string|max:120',
            'checklist_description' => 'nullable|string|max:2000',
            'checklist_time_of_day' => ['required', Rule::in(['morning', 'midday', 'evening', 'night', 'anytime'])],
            'checklist_recurrence_mode' => ['required', Rule::in(['daily', 'weekdays', 'weekends', 'one_off', 'custom'])],
            'checklist_rrule' => 'nullable|string|max:255',
            'checklist_dtstart' => 'required|date',
            'checklist_paused_until' => 'nullable|date',
            'checklist_active' => 'boolean',
            'checklist_items' => 'array',
            'checklist_items.*.label' => 'nullable|string|max:255',
            'checklist_items.*.active' => 'boolean',
            'checklist_items.*.id' => 'nullable|integer',
        ]);

        $rrule = $data['checklist_recurrence_mode'] === 'custom'
            ? trim($data['checklist_rrule'] ?? '')
            : (self::CHECKLIST_PRESET_RRULES[$data['checklist_recurrence_mode']] ?? 'FREQ=DAILY');

        $payload = [
            'name' => $data['checklist_name'],
            'description' => $data['checklist_description'] ?: null,
            'time_of_day' => $data['checklist_time_of_day'],
            'rrule' => $rrule !== '' ? $rrule : null,
            'dtstart' => $data['checklist_dtstart'],
            'paused_until' => $data['checklist_paused_until'] ?: null,
            'active' => (bool) ($data['checklist_active'] ?? true),
        ];

        if ($this->id) {
            $template = \App\Models\ChecklistTemplate::findOrFail($this->id);
            $template->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $template = \App\Models\ChecklistTemplate::create($payload);
            $this->id = $template->id;
        }

        $this->persistChecklistItems($template);
    }

    /**
     * Sync the item-repeater rows onto the template: insert rows without an
     * id, update rows that carry one, and delete rows the user removed in
     * the UI (any persisted item not present in the payload). Positions are
     * taken from the payload's array order, so drag-less reordering via the
     * up/down buttons is enough.
     */
    private function persistChecklistItems(\App\Models\ChecklistTemplate $template): void
    {
        $existingIds = $template->items()->pluck('id')->map(fn ($i) => (int) $i)->all();
        $keepIds = [];

        $position = 0;
        foreach ($this->checklist_items as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                // Skip empty rows entirely — treat as "user left blank".
                continue;
            }
            $active = (bool) ($row['active'] ?? true);
            $existingId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;

            if ($existingId && in_array($existingId, $existingIds, true)) {
                \App\Models\ChecklistTemplateItem::where('id', $existingId)->update([
                    'label' => $label,
                    'active' => $active,
                    'position' => $position,
                ]);
                $keepIds[] = $existingId;
            } else {
                $item = $template->items()->create([
                    'label' => $label,
                    'active' => $active,
                    'position' => $position,
                ]);
                $keepIds[] = (int) $item->id;
            }
            $position++;
        }

        $toDelete = array_diff($existingIds, $keepIds);
        if ($toDelete !== []) {
            \App\Models\ChecklistTemplateItem::whereIn('id', $toDelete)->delete();
        }
    }

    private function recurrenceModeForRrule(?string $rrule): string
    {
        $r = trim((string) $rrule);
        if ($r === '') {
            return 'daily';
        }
        foreach (self::CHECKLIST_PRESET_RRULES as $mode => $preset) {
            if ($r === $preset) {
                return $mode;
            }
        }

        return 'custom';
    }

    public function addItem(): void
    {
        $key = Str::uuid()->toString();
        $this->checklist_items[$key] = [
            'key' => $key,
            'id' => null,
            'label' => '',
            'active' => true,
        ];
    }

    public function removeItem(string $key): void
    {
        unset($this->checklist_items[$key]);
    }

    /**
     * Reorder `checklist_items` to match the supplied sequence of row keys.
     * The Alpine drag handler computes the new order client-side and calls
     * this method with the final DOM-order keys. Unknown keys are ignored;
     * any rows whose keys aren't in the payload keep their relative order
     * at the tail (defensive — e.g. if the DOM and server briefly diverge).
     *
     * Because `checklist_items` is a keyed assoc array whose insertion order
     * is the visible order, reordering is just "rebuild the map in the new
     * key order". wire:model bindings stay stable since each row is still
     * reached via its UUID key.
     *
     * @param  array<int, string>  $orderedKeys
     */
    public function reorderItems(array $orderedKeys): void
    {
        if ($this->checklist_items === []) {
            return;
        }

        $next = [];
        foreach ($orderedKeys as $key) {
            $k = (string) $key;
            if (isset($this->checklist_items[$k])) {
                $next[$k] = $this->checklist_items[$k];
            }
        }
        foreach ($this->checklist_items as $k => $row) {
            if (! isset($next[$k])) {
                $next[$k] = $row;
            }
        }

        $this->checklist_items = $next;
    }

    // ── Time entry (manual backlog) ──────────────────────────────────────
    //
    // Schema stores started_at + ended_at + duration_seconds. For backlog
    // entries the user cares about "I worked 2.5h on X yesterday", not the
    // clock times, so we accept a date + hours and synthesize the clock
    // window (09:00 in the user's tz → +duration) to satisfy NOT NULL.
    // loadTimeEntry + saveTimeEntry moved to App\Livewire\Inspector\TimeEntryForm.

    // transfer create+validate+pickers moved to App\Livewire\Inspector\TransferForm.

    /** @return \Illuminate\Database\Eloquent\Collection<int, Property> */
    #[Computed]
    public function propertyOptions()
    {
        return Property::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Options for the insurance "covered subject" selector — vehicles + properties + self.
     *
     * @return array<string, string> encoded-key ⇒ display label
     */
    #[Computed]
    public function insuranceSubjectOptions(): array
    {
        $options = [];
        foreach (Vehicle::orderBy('make')->get(['id', 'make', 'model', 'year']) as $v) {
            $label = trim(($v->year ? $v->year.' ' : '').(string) $v->make.' '.(string) $v->model);
            $options['vehicle:'.$v->id] = __('Vehicle').' · '.($label !== '' ? $label : __('(untitled)'));
        }
        foreach (Property::orderBy('name')->get(['id', 'name']) as $p) {
            $options['property:'.$p->id] = __('Property').' · '.$p->name;
        }
        $user = auth()->user();
        if ($user) {
            $options['user:'.$user->id] = __('Person').' · '.$user->name;
        }

        return $options;
    }

    #[On('inspector-mark-paid')]
    public function markPaid(int $projectionId): void
    {
        if ($this->asModal) {
            return;
        }
        $this->resetExcept(['open', 'asModal']);

        $projection = RecurringProjection::with('rule')->findOrFail($projectionId);
        $rule = $projection->rule;

        $this->type = 'transaction';
        $this->id = null;
        $this->open = true;
        $this->errorMessage = null;
        $this->seedDefaults();

        $this->amount = (string) $projection->amount;
        $this->currency = $projection->currency ?? 'USD';
        $this->description = $rule?->title ?? '';
        $this->account_id = $rule?->account_id;
        $this->category_id = $rule?->category_id;
        $this->counterparty_contact_id = $rule?->counterparty_contact_id;
        $this->occurred_on = $projection->due_on?->toDateString() ?? now()->toDateString();
        $this->status = 'cleared';

        $this->dispatch('inspector-body-shown');
    }

    public function createCounterparty(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $contact = Contact::create([
            'kind' => 'org',
            'display_name' => $name,
        ]);

        // Write back to whichever Livewire property the calling searchable-select
        // is bound to. The client passes `modelKey` as the second arg so this
        // works for every counterparty picker (subscription_*, contract_*,
        // account_vendor_id, inventory_vendor_id, …), not just the legacy
        // generic counterparty_contact_id field.
        $targetKey = $modelKey && property_exists($this, $modelKey)
            ? $modelKey
            : 'counterparty_contact_id';
        $this->{$targetKey} = $contact->id;
        unset($this->contacts);

        $this->dispatch('ss-option-added',
            model: $targetKey,
            id: $contact->id,
            label: $contact->display_name,
        );
    }

    public function deleteRecord(): void
    {
        if (! $this->id) {
            return;
        }

        try {
            match ($this->type) {
                'task' => Task::findOrFail($this->id)->delete(),
                'transaction' => Transaction::findOrFail($this->id)->delete(),
                'contact' => Contact::findOrFail($this->id)->delete(),
                'note' => Note::findOrFail($this->id)->delete(),
                'physical_mail' => PhysicalMail::findOrFail($this->id)->delete(),
                'bill' => RecurringRule::findOrFail($this->id)->delete(),
                'document' => Document::findOrFail($this->id)->delete(),
                'meeting' => Meeting::findOrFail($this->id)->delete(),
                'project' => Project::findOrFail($this->id)->delete(),
                'contract' => Contract::findOrFail($this->id)->delete(),
                'insurance' => Contract::findOrFail($this->id)->delete(),
                'account' => Account::findOrFail($this->id)->delete(),
                'online_account' => OnlineAccount::findOrFail($this->id)->delete(),
                'domain' => \App\Models\Domain::findOrFail($this->id)->delete(),
                'property' => Property::findOrFail($this->id)->delete(),
                'vehicle' => Vehicle::findOrFail($this->id)->delete(),
                'pet' => Pet::findOrFail($this->id)->delete(),
                'inventory' => InventoryItem::findOrFail($this->id)->delete(),
                'reminder' => \App\Models\Reminder::findOrFail($this->id)->delete(),
                'savings_goal' => \App\Models\SavingsGoal::findOrFail($this->id)->delete(),
                'budget_cap' => \App\Models\BudgetCap::findOrFail($this->id)->delete(),
                'category_rule' => \App\Models\CategoryRule::findOrFail($this->id)->delete(),
                'tag_rule' => \App\Models\TagRule::findOrFail($this->id)->delete(),
                'subscription' => \App\Models\Subscription::findOrFail($this->id)->delete(),
                'checklist_template' => \App\Models\ChecklistTemplate::findOrFail($this->id)->delete(),
                'time_entry' => \App\Models\TimeEntry::findOrFail($this->id)->delete(),
                default => null,
            };
        } catch (\App\Exceptions\PeriodLockedException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('inspector-saved', type: $this->type);
        $this->close();
    }

    /** @return array<int, string> */
    private function splitList(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $raw) ?: [])));
    }

    #[Computed]
    public function accounts()
    {
        return Account::orderBy('name')->get(['id', 'name', 'currency']);
    }

    #[Computed]
    public function categories()
    {
        return Category::with('parent:id,name')
            ->orderBy('kind')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'slug', 'parent_id']);
    }

    #[Computed]
    public function contacts()
    {
        return Contact::orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function contracts()
    {
        return Contract::orderBy('title')->get(['id', 'title']);
    }

    #[Computed]
    public function healthProviders()
    {
        return HealthProvider::orderBy('name')->get(['id', 'name', 'specialty']);
    }

    /**
     * Household members available as owner targets in the Admin section.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function householdUsers()
    {
        $household = CurrentHousehold::get();
        if (! $household) {
            return collect();
        }

        return $household->users()->orderBy('users.name')->get(['users.id', 'users.name']);
    }

    /** Per-type drawer width class. Widens for forms with many or wide fields. */
    #[Computed]
    public function drawerWidthClass(): string
    {
        return match ($this->type) {
            'transaction', 'bill', 'contract', 'vehicle', 'inventory', 'account' => 'max-w-lg',
            'note', 'project' => 'max-w-lg',
            'property', 'insurance', 'online_account' => 'max-w-xl',
            default => 'max-w-md',
        };
    }

    #[Computed]
    public function heading(): string
    {
        $label = match ($this->type) {
            'task' => __('task'),
            'transaction' => __('transaction'),
            'contact' => __('contact'),
            'note' => __('note'),
            'physical_mail' => __('post'),
            'bill' => __('bill'),
            'document' => __('document'),
            'meeting' => __('meeting'),
            'project' => __('project'),
            'contract' => __('contract'),
            'insurance' => __('insurance policy'),
            'account' => __('account'),
            'online_account' => __('online account'),
            'domain' => __('domain'),
            'property' => __('property'),
            'vehicle' => __('vehicle'),
            'pet' => __('pet'),
            'pet_vaccination' => __('pet vaccination'),
            'pet_checkup' => __('pet checkup'),
            'inventory' => __('inventory item'),
            'time_entry' => __('time entry'),
            'transfer' => __('transfer'),
            'checklist_template' => __('checklist'),
            default => '',
        };

        if ($this->type === '') {
            return __('Add');
        }

        return $this->id ? __('Edit :thing', ['thing' => $label]) : __('New :thing', ['thing' => $label]);
    }
};
?>

<div
    x-data="{
        open: @entangle('open').live,
        focusFirst() {
            // The drawer animates in over ~150ms; an input behind display:none
            // can't accept focus. Retry a handful of times until the form is
            // actually in the layout.
            const attempt = (left) => {
                const form = this.$el.querySelector('form');
                const field = form?.querySelector(
                    'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select'
                );
                if (field && field.offsetParent !== null) {
                    field.focus();
                    if (typeof field.select === 'function' && field.type !== 'date' && field.type !== 'datetime-local') {
                        field.select();
                    }
                    return;
                }
                if (left > 0) {
                    setTimeout(() => attempt(left - 1), 50);
                }
            };
            requestAnimationFrame(() => attempt(6));
        },
        init() {
            Livewire.on('inspector-body-shown', () => this.focusFirst());
        },
    }"
    @keydown.escape.window="if (open) { $wire.close() }"
    class="relative"
>
    {{-- Shell — drawer for the primary instance, centered modal for the
         sub-entity-edit variant. The two live on different z-layers so
         the modal stacks on top of an open drawer (common flow: edit a
         transaction's counterparty from inside the transaction drawer). --}}
    <div x-show="open" x-cloak x-transition.opacity
         @click="$wire.close()"
         class="fixed inset-0 {{ $asModal ? 'z-[60] bg-black/70' : 'z-40 bg-black/60' }}"
         aria-hidden="true"></div>

    <aside
        x-show="open"
        x-cloak
        @if ($asModal)
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        @else
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        @endif
        role="dialog"
        aria-modal="true"
        aria-label="{{ $asModal ? __('Sub-record editor') : __('Record inspector') }}"
        @if ($asModal)
            class="fixed left-1/2 top-1/2 z-[70] flex max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-xl border border-neutral-700 bg-neutral-950 shadow-2xl"
        @else
            class="fixed right-0 top-0 z-50 flex h-screen w-full {{ $this->drawerWidthClass }} flex-col overflow-hidden border-l border-neutral-800 bg-neutral-950 shadow-2xl transition-[max-width] duration-150"
        @endif
    >
        <header class="flex items-center justify-between border-b border-neutral-800 px-5 py-3">
            <h2 class="text-sm font-semibold text-neutral-100">{{ $this->heading }}</h2>
            <button type="button" wire:click="close" aria-label="{{ __('Close') }}"
                    class="rounded-md p-1 text-neutral-500 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        @if ($errorMessage)
            <div role="alert" class="m-4 rounded-md border border-rose-800/50 bg-rose-900/20 px-3 py-2 text-xs text-rose-300">
                {{ $errorMessage }}
            </div>
        @endif

        <div class="flex-1 overflow-y-auto px-5 py-4">
            @if ($ambiguousCandidates !== [])
                {{-- Multi-hit disambiguator: after a Transaction save that found
                     2+ candidate projections, the drawer stays open and asks the
                     user to pick which bill this paid (or skip linking). Handled
                     entirely in the drawer — no separate modal. --}}
                <section role="region" aria-label="{{ __('Pick a bill to link') }}" class="space-y-3">
                    <header>
                        <h3 class="text-sm font-semibold text-neutral-100">{{ __('Which bill did this pay?') }}</h3>
                        <p class="mt-1 text-xs text-neutral-400">{{ __('Multiple projected bills match this transaction. Pick one to link them, or skip to leave this transaction standalone.') }}</p>
                    </header>
                    <ul class="space-y-2">
                        @foreach ($ambiguousCandidates as $c)
                            <li>
                                <button type="button" wire:click="linkProjection({{ $c['id'] }})"
                                        class="flex w-full items-center justify-between gap-3 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-left text-sm text-neutral-100 hover:border-emerald-500 hover:bg-emerald-950/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ $c['title'] }}</div>
                                        <div class="text-[11px] text-neutral-500 tabular-nums">{{ __('due :d', ['d' => $c['due_on']]) }}</div>
                                    </div>
                                    <span class="shrink-0 text-sm tabular-nums text-neutral-300">{{ $c['amount'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex justify-end">
                        <button type="button" wire:click="skipProjectionLink"
                                class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Skip — leave unlinked') }}
                        </button>
                    </div>
                </section>
            @else
            @switch($type)
                @case('')
                    {{-- Type picker is primary-drawer behaviour ("Quick add").
                         The modal instance is only entered via subentity-edit-open
                         (always with a concrete type), so during a close() the
                         type resets to '' and we'd flash the picker for a frame
                         while Alpine's leave-transition ran. Render nothing in
                         modal mode. --}}
                    @unless ($asModal)
                        @include('partials.inspector.type-picker')
                    @endunless
                    @break
                @case('task')    @include('partials.inspector.forms.task')           @break
                @case('transaction') @include('partials.inspector.forms.transaction')@break
                @case('contact')
                    @livewire('inspector.contact-form', ['id' => $id], key('contact-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('bill')    @include('partials.inspector.forms.bill')           @break
                @case('note')    @include('partials.inspector.forms.note')           @break
                @case('physical_mail') @include('partials.inspector.forms.physical_mail') @break
                @case('document') @include('partials.inspector.forms.document')      @break
                @case('meeting')
                    @livewire('inspector.meeting-form', ['id' => $id], key('meeting-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('project')
                    @livewire('inspector.project-form', ['id' => $id], key('project-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('contract') @include('partials.inspector.forms.contract')      @break
                @case('insurance') @include('partials.inspector.forms.insurance')    @break
                @case('account')
                    @livewire('inspector.account-form', ['id' => $id], key('account-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('online_account')
                    @livewire('inspector.online-account-form', ['id' => $id], key('online-account-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('domain')
                    @livewire('inspector.domain-form', ['id' => $id], key('domain-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('property') @include('partials.inspector.forms.property')      @break
                @case('vehicle') @include('partials.inspector.forms.vehicle')        @break
                @case('pet')
                    {{-- Extracted to App\Livewire\Inspector\PetForm. Key
                         resets on new id so the child re-mounts cleanly
                         between "new pet" and "edit pet X" transitions. --}}
                    @livewire('inspector.pet-form', ['id' => $id], key('pet-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('pet_vaccination')
                    @livewire('inspector.pet-vaccination-form', [
                        'id' => $id,
                        'petId' => $subentityParentId,
                    ], key('pet-vaccination-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('pet_checkup')
                    @livewire('inspector.pet-checkup-form', [
                        'id' => $id,
                        'petId' => $subentityParentId,
                    ], key('pet-checkup-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('inventory') @include('partials.inspector.forms.inventory')    @break
                @case('appointment') @include('partials.inspector.forms.appointment') @break
                @case('reminder')
                    @livewire('inspector.reminder-form', ['id' => $id], key('reminder-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('savings_goal')
                    @livewire('inspector.savings-goal-form', ['id' => $id], key('savings-goal-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('budget_cap')
                    @livewire('inspector.budget-cap-form', ['id' => $id], key('budget-cap-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('category_rule')
                    @livewire('inspector.category-rule-form', ['id' => $id], key('category-rule-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('tag_rule')
                    @livewire('inspector.tag-rule-form', ['id' => $id], key('tag-rule-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('subscription')
                    @livewire('inspector.subscription-form', ['id' => $id], key('subscription-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('checklist_template') @include('partials.inspector.forms.checklist_template') @break
                @case('time_entry')
                    @livewire('inspector.time-entry-form', ['id' => $id], key('time-entry-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('transfer')
                    @livewire('inspector.transfer-form', ['id' => $id], key('transfer-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
            @endswitch
            @endif
        </div>

        @if ($type !== '' && $ambiguousCandidates === [])
            <footer class="flex items-center justify-between border-t border-neutral-800 bg-neutral-900/50 px-5 py-3">
                <div>
                    @if ($id)
                        <button type="button"
                                wire:click="deleteRecord"
                                wire:confirm="{{ __('Delete this record? This cannot be undone.') }}"
                                class="rounded-md border border-rose-800/40 px-3 py-1.5 text-xs text-rose-300 hover:bg-rose-900/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Delete') }}
                        </button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="close"
                            class="rounded-md px-3 py-1.5 text-xs text-neutral-400 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="save"
                            class="rounded-md bg-neutral-100 px-4 py-1.5 text-xs font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        <span wire:loading.remove wire:target="save">{{ $id ? __('Save') : __('Create') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </footer>
        @endif
    </aside>
</div>
