<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicySubject;
use App\Models\InventoryItem;
use App\Models\Meeting;
use App\Models\OnlineAccount;
use App\Models\Note;
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

    // Admin-section meta (read-only display, loaded from the record on edit).
    public ?int $admin_owner_id = null;

    public string $admin_owner_label = '';

    public string $admin_created_at = '';

    public string $admin_updated_at = '';

    // task
    public string $due_at = '';

    public int $priority = 3;

    public string $state = 'open';

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

    // contact
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

    // meeting
    public string $meeting_title = '';

    public string $starts_at = '';

    public string $ends_at = '';

    public string $location = '';

    public bool $all_day = false;

    public string $meeting_url = '';

    // project
    public string $project_name = '';

    public string $project_slug = '';

    public string $project_color = '';

    public bool $project_billable = false;

    public string $project_hourly_rate = '';

    public string $project_hourly_rate_currency = 'USD';

    public ?int $project_client_id = null;

    public bool $project_archived = false;

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

    // account
    public string $account_name = '';

    public string $account_type = 'bank';

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

    // online_account
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

    #[On('inspector-open')]
    public function openInspector(string $type = '', ?int $id = null): void
    {
        $this->resetExcept(['open']);
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
        }

        $this->dispatch('inspector-body-shown');
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
            'meeting' => [Meeting::class, 'organizer_user_id'],
            'contact' => [Contact::class, 'owner_user_id'],
            'account' => [Account::class, 'user_id'],
            'contract' => [Contract::class, 'primary_user_id'],
            'insurance' => [Contract::class, 'primary_user_id'],
            'property' => [Property::class, 'primary_user_id'],
            'vehicle' => [Vehicle::class, 'primary_user_id'],
            'inventory' => [InventoryItem::class, 'owner_user_id'],
            'document' => [Document::class, 'holder_user_id'],
            'note' => [Note::class, 'user_id'],
            'project' => [Project::class, 'user_id'],
            'online_account' => [OnlineAccount::class, 'user_id'],
            'transaction' => [Transaction::class, null],
            'bill' => [RecurringRule::class, null],
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
        $this->reset();
    }

    private function seedDefaults(): void
    {
        $currency = $this->householdCurrency();

        $this->occurred_on = now()->toDateString();
        $this->due_at = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
        $this->currency = $currency;
        $this->project_hourly_rate_currency = $currency;
        $this->contract_monthly_cost_currency = $currency;
        $this->property_purchase_currency = $currency;
        $this->vehicle_purchase_currency = $currency;
        $this->vehicle_registration_fee_currency = $currency;
        $this->inventory_cost_currency = $currency;
        $this->insurance_premium_currency = $currency;
        $this->insurance_coverage_currency = $currency;
        $this->insurance_deductible_currency = $currency;
        $this->account_currency = $currency;
        $this->sale_currency = $currency;
        $this->issued_on = now()->toDateString();
        $this->due_on = now()->addDays(14)->toDateString();
        $this->doc_issued_on = now()->toDateString();
        $this->starts_at = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
        $this->ends_at = now()->addDay()->startOfHour()->addMinutes(30)->format('Y-m-d\TH:i');
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
            'contact' => $this->loadContact(),
            'note' => $this->loadNote(),
            'bill' => $this->loadBill(),
            'document' => $this->loadDocument(),
            'meeting' => $this->loadMeeting(),
            'project' => $this->loadProject(),
            'contract' => $this->loadContract(),
            'insurance' => $this->loadInsurance(),
            'account' => $this->loadAccount(),
            'online_account' => $this->loadOnlineAccount(),
            'property' => $this->loadProperty(),
            'vehicle' => $this->loadVehicle(),
            'inventory' => $this->loadInventory(),
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
    }

    private function loadContact(): void
    {
        $c = Contact::findOrFail($this->id);
        $this->kind = $c->kind;
        $this->display_name = $c->display_name;
        $this->first_name = $c->first_name ?? '';
        $this->last_name = $c->last_name ?? '';
        $this->organization = $c->organization ?? '';
        $this->favorite = (bool) $c->favorite;
        $this->is_vendor = (bool) $c->is_vendor;
        $this->is_customer = (bool) $c->is_customer;
        $this->tax_id = $c->tax_id ?? '';
        $emails = $c->emails ?? [];
        $this->email = is_array($emails) ? implode(', ', $emails) : (string) $emails;
        $phones = $c->phones ?? [];
        $this->phone = is_array($phones) ? implode(', ', $phones) : (string) $phones;
        $this->notes = $c->notes ?? '';
    }

    private function loadNote(): void
    {
        $n = Note::findOrFail($this->id);
        $this->title = $n->title ?? '';
        $this->body = $n->body;
        $this->pinned = (bool) $n->pinned;
        $this->private = (bool) $n->private;
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

    private function loadMeeting(): void
    {
        $m = Meeting::findOrFail($this->id);
        $this->meeting_title = $m->title;
        $this->starts_at = $m->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ends_at = $m->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->location = $m->location ?? '';
        $this->all_day = (bool) $m->all_day;
        $this->notes = $m->notes ?? '';
        $this->meeting_url = $m->url ?? '';
    }

    private function loadProject(): void
    {
        $p = Project::findOrFail($this->id);
        $this->project_name = $p->name;
        $this->project_slug = $p->slug;
        $this->project_color = $p->color ?? '';
        $this->project_billable = (bool) $p->billable;
        $this->project_hourly_rate = $p->hourly_rate !== null ? (string) $p->hourly_rate : '';
        $this->project_hourly_rate_currency = $p->hourly_rate_currency ?: 'USD';
        $this->project_client_id = $p->client_contact_id;
        $this->project_archived = (bool) $p->archived;
        $this->notes = $p->notes ?? '';
    }

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
        $this->notes = $c->notes ?? '';
    }

    private function loadAccount(): void
    {
        $a = Account::where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->findOrFail($this->id);
        $this->account_name = $a->name;
        $this->account_type = $a->type;
        $this->account_currency = $a->currency ?: $this->householdCurrency();
        $this->account_opening_balance = $a->opening_balance !== null ? (string) $a->opening_balance : '0';
        $this->account_institution = $a->institution ?? '';
        $this->account_vendor_id = $a->vendor_contact_id;
        $this->account_expires_on = $a->expires_on?->toDateString() ?? '';
        $this->account_is_active = (bool) ($a->is_active ?? true);
        $this->account_include_in_net_worth = (bool) ($a->include_in_net_worth ?? true);
        $this->account_number_mask = $a->account_number_mask ?? '';
        $this->account_opened_on = $a->opened_on?->toDateString() ?? '';
        $this->account_closed_on = $a->closed_on?->toDateString() ?? '';
        $this->notes = $a->notes ?? '';
    }

    private function loadOnlineAccount(): void
    {
        $o = OnlineAccount::where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->findOrFail($this->id);
        $this->oa_kind = $o->kind ?: 'other';
        $this->oa_service_name = $o->service_name;
        $this->oa_url = $o->url ?? '';
        $this->oa_login_email = $o->login_email ?? '';
        $this->oa_username = $o->username ?? '';
        $this->oa_mfa_method = $o->mfa_method ?: 'none';
        $this->oa_recovery_contact_id = $o->recovery_contact_id;
        $this->oa_linked_contract_id = $o->linked_contract_id;
        $this->oa_importance_tier = $o->importance_tier ?: 'medium';
        $this->oa_in_case_of_pack = (bool) $o->in_case_of_pack;
        $this->oa_notes = $o->notes ?? '';
    }

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
        try {
            match ($this->type) {
                'task' => $this->saveTask(),
                'transaction' => $this->saveTransaction(),
                'contact' => $this->saveContact(),
                'note' => $this->saveNote(),
                'bill' => $this->saveBill(),
                'document' => $this->saveDocument(),
                'meeting' => $this->saveMeeting(),
                'project' => $this->saveProject(),
                'contract' => $this->saveContract(),
                'insurance' => $this->saveInsurance(),
                'account' => $this->saveAccount(),
                'online_account' => $this->saveOnlineAccount(),
                'property' => $this->saveProperty(),
                'vehicle' => $this->saveVehicle(),
                'inventory' => $this->saveInventory(),
                default => null,
            };
        } catch (\App\Exceptions\PeriodLockedException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->dispatch('inspector-saved', type: $this->type);
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
            Task::findOrFail($this->id)->update($data);
        } else {
            $data['assigned_user_id'] = auth()->id();
            $this->id = Task::create($data)->id;
        }
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
            Transaction::findOrFail($this->id)->update($data);
        } else {
            $transaction = Transaction::create($data);
            $this->id = $transaction->id;
            ProjectionMatcher::attempt($transaction);
        }
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

    private function saveContact(): void
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
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = array_filter([
            'kind' => $data['kind'],
            'display_name' => $data['display_name'],
            'first_name' => $data['first_name'] ?: null,
            'last_name' => $data['last_name'] ?: null,
            'organization' => $data['organization'] ?: null,
            'favorite' => $data['favorite'],
            'is_vendor' => $data['is_vendor'],
            'is_customer' => $data['is_customer'],
            'tax_id' => $data['tax_id'] ?: null,
            'emails' => $this->splitList($data['email']),
            'phones' => $this->splitList($data['phone']),
            'notes' => $data['notes'] ?: null,
        ], fn ($v) => $v !== null);

        // always-present booleans should NOT be filtered out when false
        $payload['favorite'] = $data['favorite'];
        $payload['is_vendor'] = $data['is_vendor'];
        $payload['is_customer'] = $data['is_customer'];

        if ($this->id) {
            Contact::findOrFail($this->id)->update($payload);
        } else {
            $payload['owner_user_id'] = auth()->id();
            $this->id = Contact::create($payload)->id;
        }
    }

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
            Note::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = Note::create($payload)->id;
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

    private function saveMeeting(): void
    {
        $data = $this->validate([
            'meeting_title' => 'required|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'location' => 'nullable|string|max:255',
            'all_day' => 'boolean',
            'notes' => 'nullable|string|max:5000',
            'meeting_url' => 'nullable|string|max:500',
        ]);

        $payload = [
            'title' => $data['meeting_title'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'location' => $data['location'] ?: null,
            'all_day' => (bool) $data['all_day'],
            'notes' => $data['notes'] ?: null,
            'url' => $data['meeting_url'] ?: null,
        ];

        if ($this->id) {
            Meeting::findOrFail($this->id)->update($payload);
        } else {
            $payload['organizer_user_id'] = auth()->id();
            $this->id = Meeting::create($payload)->id;
        }
    }

    private function saveProject(): void
    {
        $data = $this->validate([
            'project_name' => 'required|string|max:255',
            'project_slug' => 'nullable|string|max:255',
            'project_color' => 'nullable|string|size:7|starts_with:#',
            'project_billable' => 'boolean',
            'project_hourly_rate' => 'nullable|numeric',
            'project_hourly_rate_currency' => 'nullable|string|size:3',
            'project_client_id' => 'nullable|integer|exists:contacts,id',
            'project_archived' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);

        $slug = $data['project_slug'] ?: Str::slug($data['project_name']);

        $payload = [
            'name' => $data['project_name'],
            'slug' => $slug,
            'color' => $data['project_color'] ?: null,
            'billable' => (bool) $data['project_billable'],
            'hourly_rate' => $data['project_hourly_rate'] !== '' ? (float) $data['project_hourly_rate'] : null,
            'hourly_rate_currency' => $data['project_hourly_rate_currency'] ?: null,
            'client_contact_id' => $data['project_client_id'] ?: null,
            'archived' => (bool) $data['project_archived'],
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->id) {
            Project::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = Project::create($payload)->id;
        }
    }

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

    private function saveAccount(): void
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

        if ($this->id) {
            Account::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = Account::create($payload)->id;
        }
    }

    private function saveOnlineAccount(): void
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

        if ($this->id) {
            OnlineAccount::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = OnlineAccount::create($payload)->id;
        }
    }

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
        $this->resetExcept(['open']);

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

    public function createCounterparty(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $contact = Contact::create([
            'kind' => 'org',
            'display_name' => $name,
        ]);

        $this->counterparty_contact_id = $contact->id;
        unset($this->contacts);

        $this->dispatch('ss-option-added',
            model: 'counterparty_contact_id',
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
                'bill' => RecurringRule::findOrFail($this->id)->delete(),
                'document' => Document::findOrFail($this->id)->delete(),
                'meeting' => Meeting::findOrFail($this->id)->delete(),
                'project' => Project::findOrFail($this->id)->delete(),
                'contract' => Contract::findOrFail($this->id)->delete(),
                'insurance' => Contract::findOrFail($this->id)->delete(),
                'account' => Account::findOrFail($this->id)->delete(),
                'online_account' => OnlineAccount::findOrFail($this->id)->delete(),
                'property' => Property::findOrFail($this->id)->delete(),
                'vehicle' => Vehicle::findOrFail($this->id)->delete(),
                'inventory' => InventoryItem::findOrFail($this->id)->delete(),
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
        return Category::orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind', 'slug']);
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
            'bill' => __('bill'),
            'document' => __('document'),
            'meeting' => __('meeting'),
            'project' => __('project'),
            'contract' => __('contract'),
            'insurance' => __('insurance policy'),
            'account' => __('account'),
            'online_account' => __('online account'),
            'property' => __('property'),
            'vehicle' => __('vehicle'),
            'inventory' => __('inventory item'),
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
    <div x-show="open" x-cloak x-transition.opacity
         @click="$wire.close()"
         class="fixed inset-0 z-40 bg-black/60"
         aria-hidden="true"></div>

    <aside
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Record inspector') }}"
        class="fixed right-0 top-0 z-50 flex h-screen w-full {{ $this->drawerWidthClass }} flex-col overflow-hidden border-l border-neutral-800 bg-neutral-950 shadow-2xl transition-[max-width] duration-150"
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
            @switch($type)
                @case('')        @include('partials.inspector.type-picker')          @break
                @case('task')    @include('partials.inspector.forms.task')           @break
                @case('transaction') @include('partials.inspector.forms.transaction')@break
                @case('contact') @include('partials.inspector.forms.contact')        @break
                @case('bill')    @include('partials.inspector.forms.bill')           @break
                @case('note')    @include('partials.inspector.forms.note')           @break
                @case('document') @include('partials.inspector.forms.document')      @break
                @case('meeting') @include('partials.inspector.forms.meeting')        @break
                @case('project') @include('partials.inspector.forms.project')        @break
                @case('contract') @include('partials.inspector.forms.contract')      @break
                @case('insurance') @include('partials.inspector.forms.insurance')    @break
                @case('account') @include('partials.inspector.forms.account')        @break
                @case('online_account') @include('partials.inspector.forms.online_account') @break
                @case('property') @include('partials.inspector.forms.property')      @break
                @case('vehicle') @include('partials.inspector.forms.vehicle')        @break
                @case('inventory') @include('partials.inspector.forms.inventory')    @break
            @endswitch
        </div>

        @if ($type !== '')
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
