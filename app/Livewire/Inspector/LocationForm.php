<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\Location;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Location inspector form. Parent-picker rejects descendants to guard
 * against cycles through the UI; save() re-validates at the model
 * layer via descendantIds() so nothing slips through.
 */
class LocationForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public string $location_name = '';

    public string $location_kind = 'room';

    public ?int $location_parent_id = null;

    public ?int $location_property_id = null;

    public function mount(?int $id = null, ?int $parentId = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $loc = Location::findOrFail($id);
            $this->location_name = (string) $loc->name;
            $this->location_kind = (string) $loc->kind;
            $this->location_parent_id = $loc->parent_id;
            $this->location_property_id = $loc->property_id;
        } elseif ($parentId !== null) {
            $this->location_parent_id = $parentId;
            $parent = Location::find($parentId);
            if ($parent !== null) {
                $this->location_property_id = $parent->property_id;
            }
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'location_name' => 'required|string|max:128',
            'location_kind' => ['required', Rule::in(['area', 'room', 'container', 'other'])],
            'location_property_id' => 'nullable|integer|exists:properties,id',
            'location_parent_id' => [
                'nullable',
                'integer',
                'exists:locations,id',
                fn ($attr, $value, $fail) => $value === $this->id
                    ? $fail(__('A location cannot be its own parent.'))
                    : null,
                fn ($attr, $value, $fail) => $value !== null && $this->id !== null
                    && in_array((int) $value, Location::findOrFail($this->id)->descendantIds(), true)
                    ? $fail(__('Cannot pick a descendant as the parent.'))
                    : null,
            ],
        ]);

        $payload = [
            'name' => $data['location_name'],
            'kind' => $data['location_kind'],
            'parent_id' => $data['location_parent_id'] ?: null,
            'property_id' => $data['location_property_id'] ?: null,
        ];

        if ($this->id !== null) {
            Location::findOrFail($this->id)->update($payload);
        } else {
            $loc = Location::create($payload);
            $this->id = (int) $loc->id;
        }

        $this->finalizeSave();
    }

    /**
     * Parent-picker options. Excludes the current location and its
     * descendants so the user can't create a cycle through the UI.
     * Labels render as breadcrumbs so deep nesting is legible.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentPickerOptions(): array
    {
        $excluded = $this->id !== null
            ? Location::findOrFail($this->id)->descendantIds()
            : [];

        return Location::query()
            ->whereNotIn('id', $excluded)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->id => $l->breadcrumb()])
            ->all();
    }

    /** @return array<int, string> */
    #[Computed]
    public function propertyPickerOptions(): array
    {
        return Property::orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        return view('livewire.inspector.location-form');
    }
}
