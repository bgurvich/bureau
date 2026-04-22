<?php

use App\Exceptions\PeriodLockedException;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\ChecklistTemplate;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Domain;
use App\Models\HealthProvider;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\OnlineAccount;
use App\Models\Pet;
use App\Models\PhysicalMail;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\TagRule;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use App\Support\ProjectionMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
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

    // inventory extracted to App\Livewire\Inspector\InventoryForm;
    // the shell hosts the child via @livewire in the render switch.
    // (shared disposition fields moved there too — now carried inline
    // on each asset form since vehicle/property/inventory are all extracted.)

    // appointment extracted to App\Livewire\Inspector\AppointmentForm;
    // the shell hosts the child via @livewire in the render switch.

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

    // note extracted to App\Livewire\Inspector\NoteForm;
    // the shell hosts the child via @livewire in the render switch.

    // bill
    public string $bill_title = '';

    public string $issued_on = '';

    public string $due_on = '';

    public bool $is_recurring = false;

    public string $frequency = 'monthly';

    public bool $autopay = false;

    public string $bill_until = '';

    public int $bill_lead_days = 7;

    // document extracted to App\Livewire\Inspector\DocumentForm;
    // the shell hosts the child via @livewire in the render switch.

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

    // property extracted to App\Livewire\Inspector\PropertyForm;
    // the shell hosts the child via @livewire in the render switch.

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

    // vehicle extracted to App\Livewire\Inspector\VehicleForm;
    // the shell hosts the child via @livewire in the render switch.

    // (inventory state moved to InventoryForm — see above.)

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

    /**
     * Set by markPaid() to hand a RecurringProjection's values to the
     * extracted TransactionForm at mount time. Cleared after the child
     * mounts so subsequent openInspector('transaction') calls don't
     * redundantly prefill.
     */
    public ?int $projection_prefill_id = null;

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

    /**
     * TransactionForm detected multiple candidate RecurringProjections
     * for the just-saved transaction. Swap the drawer body to the picker
     * UI — linkProjection + skipProjectionLink live on this shell and
     * operate on $ambiguousTransactionId + $ambiguousCandidates.
     *
     * @param  array<int, array{id:int, title:string, due_on:string, amount:string}>  $candidates
     */
    #[On('inspector-projection-candidates')]
    public function onProjectionCandidates(int $transactionId, array $candidates): void
    {
        $this->ambiguousTransactionId = $transactionId;
        $this->ambiguousCandidates = $candidates;
    }

    /**
     * Resolve multi-hit ambiguity: link the chosen projection to the
     * just-saved transaction and clear the picker state.
     */
    public function linkProjection(int $projectionId): void
    {
        if (! $this->ambiguousTransactionId) {
            return;
        }
        // Only link projections the matcher proposed — defense against a
        // race where $ambiguousCandidates is stale but the component
        // still receives a click.
        $allowed = array_column($this->ambiguousCandidates, 'id');
        if (! in_array($projectionId, $allowed, true)) {
            return;
        }
        RecurringProjection::where('id', $projectionId)
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

    /** Skip the picker — save the transaction without linking. */
    public function skipProjectionLink(): void
    {
        $this->ambiguousCandidates = [];
        $this->ambiguousTransactionId = null;
        $this->dispatch('inspector-saved');
        $this->close();
    }

    private function doOpen(string $type, ?int $id, ?int $mediaId = null, ?int $parentId = null): void
    {
        $this->resetExcept(['open', 'asModal']);
        $this->type = $type;
        $this->id = $id;
        $this->open = true;
        $this->errorMessage = null;

        // Child form mount() reads $id / $mediaId / $projection_prefill_id
        // and handles its own hydration. Shell just latches the three
        // inputs so the @livewire(...) call in the @case switch can pass
        // them to the child as mount params.
        if ($mediaId) {
            $this->source_media_id = $mediaId;
        }
        // Pre-seed the parent FK for sub-entity forms (pet_vaccination,
        // pet_checkup); the child's mount() reads this via the `petId`
        // prop passed from the corresponding @case render switch.
        $this->subentityParentId = $parentId;

        $this->dispatch('inspector-body-shown');
    }

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

            /** @var Illuminate\Database\Eloquent\Collection<int, Model> $rows */
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

            /** @var Illuminate\Database\Eloquent\Collection<int, Model> $rows */
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
    private function subjectRefsFrom(Model $model): array
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

    // attachSourceMediaTo() moved to BillForm + TransactionForm; each
    // child attaches the OCR-scan source to its own record on create.

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
            'checklist_template' => [ChecklistTemplate::class, 'user_id'],
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

        /** @var Model|null $model */
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
     * @return Collection<int, Media>
     */
    public function inspectorPhotos(): Collection
    {
        [$class] = $this->adminModelMap();
        if (! $class || ! $this->id || ! method_exists($class, 'media')) {
            return collect();
        }

        /** @var Model|null $model */
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
    /**
     * Shell-side no-op — the photo-first flow (inventory) now runs in
     * InventoryForm, which has its own ensureDraftForPhoto override.
     * Contract is the only remaining shell type using photos and it
     * doesn't support photo-first creation.
     */
    private function ensureDraftForPhoto(): void
    {
        // no-op
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

        /** @var Model|null $model */
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

        /** @var Model|null $model */
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

    // seedDefaults() was the shell's "on new record, populate default
    // dates/currencies" helper. Every extracted form now seeds its own
    // defaults in mount() when no id is passed, so the shell stays out
    // of per-type business.

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
        return Category::with('parent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'parent_id'])
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->displayLabel()])
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
        return Contact::orderBy('display_name')->pluck('display_name', 'id')->all();
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
        return RecurringRule::where('active', true)
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
        return Contract::whereNotIn('state', ['ended', 'cancelled'])
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
        $slug = Str::slug($name);
        $base = $slug === '' ? 'cat-'.bin2hex(random_bytes(3)) : $slug;
        $suffix = 0;
        while (Category::where('slug', $suffix ? "{$base}-{$suffix}" : $base)->exists()) {
            $suffix++;
        }
        $category = Category::create([
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

    // loadRecord() no longer runs — every type is an extracted child
    // Livewire component that hydrates from its own mount($id). Kept
    // removed intentionally: keeping a dispatcher would re-introduce
    // shell-level state the extracted forms have already owned.

    public function save(): void
    {
        // Extracted form types: the shell's Save button lands here, we
        // fan out an `inspector-save` event, and the matching child
        // component (App\Livewire\Inspector\{Type}Form) validates +
        // persists on its own. The child fires `inspector-form-saved`
        // back and the shell's onFormSaved() listener closes the drawer.
        // Add a type to this array after extracting its child form.
        // Every inspector type now runs as an extracted child component.
        // Shell fans out `inspector-save`; the matching child form
        // validates + persists + fires `inspector-form-saved` back; the
        // shell's onFormSaved() listener closes the drawer. TransactionForm
        // additionally fires `inspector-projection-candidates` when
        // ProjectionMatcher returns multi-hit; onProjectionCandidates()
        // stashes them on the shell and the picker UI takes over.
        $this->dispatch('inspector-save');
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

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        if ((int) ($model->{$userField} ?? 0) === (int) ($newOwner ?? 0)) {
            return;
        }

        $model->forceFill([$userField => $newOwner])->save();
    }

    // saveTask moved to App\Livewire\Inspector\TaskForm.

    // saveTransaction moved to App\Livewire\Inspector\TransactionForm.
    // The form also owns prefillFromMedia / prefillFromProjection and
    // fires `inspector-projection-candidates` on multi-hit matches.

    // saveBill moved to App\Livewire\Inspector\BillForm; autoFillInterestCounterparty
    // + materializeInitialProjection + prefillFromMedia migrated with it.

    // saveContract moved to App\Livewire\Inspector\ContractForm.

    // saveInsurance moved to App\Livewire\Inspector\InsuranceForm;
    // encodeSubject + decodeSubject moved with it.

    // saveProperty moved to App\Livewire\Inspector\PropertyForm.

    // saveVehicle moved to App\Livewire\Inspector\VehicleForm.

    // loadPet + savePet moved to App\Livewire\Inspector\PetForm as
    // part of the inspector refactor pilot. The shell's save() forks
    // on type=='pet' and dispatches 'inspector-save' so the child
    // handles validation and persistence; an `inspector-form-saved`
    // bounce from the child closes the drawer.

    // loadPetVaccination/savePetVaccination + loadPetCheckup/savePetCheckup
    // moved to App\Livewire\Inspector\PetVaccinationForm and
    // App\Livewire\Inspector\PetCheckupForm. Both are class-based so
    // PHPStan sees the code + tests drive them directly.

    // saveInventory moved to App\Livewire\Inspector\InventoryForm.

    // appointment load/save moved to App\Livewire\Inspector\AppointmentForm.

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

    // Checklist-template load/save/repeater methods moved to
    // App\Livewire\Inspector\ChecklistTemplateForm. The shell no longer
    // stores checklist state or defines addItem/removeItem/reorderItems —
    // the child component owns them and renders via @livewire.

    // ── Time entry (manual backlog) ──────────────────────────────────────
    //
    // Schema stores started_at + ended_at + duration_seconds. For backlog
    // entries the user cares about "I worked 2.5h on X yesterday", not the
    // clock times, so we accept a date + hours and synthesize the clock
    // window (09:00 in the user's tz → +duration) to satisfy NOT NULL.
    // loadTimeEntry + saveTimeEntry moved to App\Livewire\Inspector\TimeEntryForm.

    // transfer create+validate+pickers moved to App\Livewire\Inspector\TransferForm.

    /** @return Illuminate\Database\Eloquent\Collection<int, Property> */
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

        // TransactionForm reads `projection_prefill_id` on mount and
        // seeds amount/description/account from the projection itself.
        // Blade `key()` on the child depends on this id so the child
        // component re-mounts with the fresh projection context.
        $this->type = 'transaction';
        $this->id = null;
        $this->projection_prefill_id = $projectionId;
        $this->open = true;
        $this->errorMessage = null;

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
                'domain' => Domain::findOrFail($this->id)->delete(),
                'property' => Property::findOrFail($this->id)->delete(),
                'vehicle' => Vehicle::findOrFail($this->id)->delete(),
                'pet' => Pet::findOrFail($this->id)->delete(),
                'inventory' => InventoryItem::findOrFail($this->id)->delete(),
                'reminder' => Reminder::findOrFail($this->id)->delete(),
                'savings_goal' => SavingsGoal::findOrFail($this->id)->delete(),
                'budget_cap' => BudgetCap::findOrFail($this->id)->delete(),
                'category_rule' => CategoryRule::findOrFail($this->id)->delete(),
                'tag_rule' => TagRule::findOrFail($this->id)->delete(),
                'subscription' => Subscription::findOrFail($this->id)->delete(),
                'checklist_template' => ChecklistTemplate::findOrFail($this->id)->delete(),
                'time_entry' => TimeEntry::findOrFail($this->id)->delete(),
                default => null,
            };
        } catch (PeriodLockedException $e) {
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
     * @return Collection<int, User>
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
                @case('task')
                    @livewire('inspector.task-form', ['id' => $id], key('task-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('transaction')
                    @livewire('inspector.transaction-form', ['id' => $id, 'mediaId' => $source_media_id, 'projectionId' => $projection_prefill_id], key('transaction-form-'.($id ?? 'new').'-'.($source_media_id ?? '0').'-'.($projection_prefill_id ?? '0').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('contact')
                    @livewire('inspector.contact-form', ['id' => $id], key('contact-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('bill')
                    @livewire('inspector.bill-form', ['id' => $id, 'mediaId' => $source_media_id], key('bill-form-'.($id ?? 'new').'-'.($source_media_id ?? '0').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('note')
                    @livewire('inspector.note-form', ['id' => $id], key('note-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('physical_mail')
                    @livewire('inspector.physical-mail-form', ['id' => $id], key('physical-mail-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('document')
                    @livewire('inspector.document-form', ['id' => $id], key('document-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('meeting')
                    @livewire('inspector.meeting-form', ['id' => $id], key('meeting-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('project')
                    @livewire('inspector.project-form', ['id' => $id], key('project-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('contract')
                    @livewire('inspector.contract-form', ['id' => $id], key('contract-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('insurance')
                    @livewire('inspector.insurance-form', ['id' => $id], key('insurance-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('account')
                    @livewire('inspector.account-form', ['id' => $id], key('account-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('online_account')
                    @livewire('inspector.online-account-form', ['id' => $id], key('online-account-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('domain')
                    @livewire('inspector.domain-form', ['id' => $id], key('domain-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('property')
                    @livewire('inspector.property-form', ['id' => $id], key('property-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('vehicle')
                    @livewire('inspector.vehicle-form', ['id' => $id], key('vehicle-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
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
                @case('inventory')
                    @livewire('inspector.inventory-form', ['id' => $id], key('inventory-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
                @case('appointment')
                    @livewire('inspector.appointment-form', ['id' => $id], key('appointment-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
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
                @case('checklist_template')
                    @livewire('inspector.checklist-template-form', ['id' => $id], key('checklist-template-form-'.($id ?? 'new').'-'.($asModal ? 'm' : 'p')))
                    @break
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
