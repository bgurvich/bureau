<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Checklist-template form (morning routine, onboarding
 * checklist, pre-trip packing, …). The schedule is stored as an RRULE;
 * the UI exposes four presets + a custom-RRULE escape hatch. Items are
 * a keyed repeater so drag-and-drop reorder stays stable across the
 * Livewire round-trip.
 */
class ChecklistTemplateForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    /** @var array<string, string> */
    private const PRESET_RRULES = [
        'daily' => 'FREQ=DAILY',
        'weekdays' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'weekends' => 'FREQ=WEEKLY;BYDAY=SA,SU',
        // One-off: single occurrence on dtstart. Needed for onboarding
        // + move-house + welcome-a-pet lists where a cadence doesn't fit.
        'one_off' => 'FREQ=DAILY;COUNT=1',
    ];

    public ?int $id = null;

    public string $checklist_name = '';

    public string $checklist_description = '';

    public string $checklist_time_of_day = 'anytime';

    public string $checklist_recurrence_mode = 'daily';

    public string $checklist_rrule = '';

    public string $checklist_dtstart = '';

    public string $checklist_paused_until = '';

    public bool $checklist_active = true;

    /** @var array<string, array{key:string, id:?int, label:string, active:bool}> */
    public array $checklist_items = [];

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $t = ChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('position')])
                ->findOrFail($id);

            $this->checklist_name = (string) $t->name;
            $this->checklist_description = (string) ($t->description ?? '');
            $this->checklist_time_of_day = $t->time_of_day ?? 'anytime';
            $this->checklist_rrule = (string) ($t->rrule ?? '');
            $this->checklist_dtstart = $t->dtstart ? $t->dtstart->toDateString() : now()->toDateString();
            $this->checklist_paused_until = $t->paused_until ? $t->paused_until->toDateString() : '';
            $this->checklist_active = (bool) $t->active;
            $this->checklist_recurrence_mode = $this->recurrenceModeForRrule($this->checklist_rrule);

            // Items are stored as a key-keyed associative array so every
            // `wire:model="checklist_items.{key}.label"` binding stays
            // stable across drag-and-drop reorders. PHP arrays preserve
            // insertion order, which also carries the visual order.
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

            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->checklist_dtstart = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
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
            : (self::PRESET_RRULES[$data['checklist_recurrence_mode']] ?? 'FREQ=DAILY');

        $payload = [
            'name' => $data['checklist_name'],
            'description' => $data['checklist_description'] ?: null,
            'time_of_day' => $data['checklist_time_of_day'],
            'rrule' => $rrule !== '' ? $rrule : null,
            'dtstart' => $data['checklist_dtstart'],
            'paused_until' => $data['checklist_paused_until'] ?: null,
            'active' => (bool) ($data['checklist_active'] ?? true),
        ];

        if ($this->id !== null) {
            $template = ChecklistTemplate::findOrFail($this->id);
            $template->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $template = ChecklistTemplate::create($payload);
            $this->id = (int) $template->id;
        }

        $this->persistChecklistItems($template);

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
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
     * Reorder `checklist_items` to match the supplied sequence of row
     * keys. The Alpine drag handler computes the new order client-side
     * and calls here with the final DOM-order keys. Unknown keys are
     * ignored; rows whose keys aren't in the payload keep their relative
     * order at the tail (defensive — e.g. when DOM/server briefly drift).
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

    /**
     * Sync the item-repeater rows onto the template: insert new rows,
     * update known rows, and delete rows the user removed. Positions
     * are taken from the payload's array order so drag reorders stick.
     */
    private function persistChecklistItems(ChecklistTemplate $template): void
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
                ChecklistTemplateItem::where('id', $existingId)->update([
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
            ChecklistTemplateItem::whereIn('id', $toDelete)->delete();
        }
    }

    private function recurrenceModeForRrule(?string $rrule): string
    {
        $r = trim((string) $rrule);
        if ($r === '') {
            return 'daily';
        }
        foreach (self::PRESET_RRULES as $mode => $preset) {
            if ($r === $preset) {
                return $mode;
            }
        }

        return 'custom';
    }

    protected function adminOwnerClass(): ?string
    {
        return ChecklistTemplate::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.checklist-template-form');
    }
}
