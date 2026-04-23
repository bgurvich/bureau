<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\FoodEntry;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Food intake entry. Default eaten_at = now so "ate this right now"
 * flow is a couple of taps: pick kind, type the label, hit save.
 * Nutrition fields are all nullable — a rough log is still useful
 * even if the user only fills calories for half the rows.
 */
class FoodEntryForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;

    public ?int $id = null;

    public string $kind = 'meal';

    public string $label = '';

    public string $eaten_at = '';

    public string $servings = '';

    public string $calories = '';

    public string $protein_g = '';

    public string $carbs_g = '';

    public string $fat_g = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $e = FoodEntry::findOrFail($id);
            $this->kind = (string) $e->kind;
            $this->label = (string) $e->label;
            $this->eaten_at = $e->eaten_at->format('Y-m-d\TH:i');
            $this->servings = $e->servings !== null ? (string) $e->servings : '';
            $this->calories = $e->calories !== null ? (string) $e->calories : '';
            $this->protein_g = $e->protein_g !== null ? (string) $e->protein_g : '';
            $this->carbs_g = $e->carbs_g !== null ? (string) $e->carbs_g : '';
            $this->fat_g = $e->fat_g !== null ? (string) $e->fat_g : '';
            $this->notes = (string) ($e->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->eaten_at = now()->format('Y-m-d\TH:i');
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'kind' => ['required', Rule::in(array_keys(Enums::foodEntryKinds()))],
            'label' => 'required|string|max:255',
            'eaten_at' => 'required|date',
            'servings' => 'nullable|numeric|min:0',
            'calories' => 'nullable|integer|min:0|max:20000',
            'protein_g' => 'nullable|numeric|min:0|max:1000',
            'carbs_g' => 'nullable|numeric|min:0|max:1000',
            'fat_g' => 'nullable|numeric|min:0|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'kind' => $data['kind'],
            'label' => $data['label'],
            'eaten_at' => $data['eaten_at'],
            'servings' => $data['servings'] !== '' ? (float) $data['servings'] : null,
            'calories' => $data['calories'] !== '' ? (int) $data['calories'] : null,
            'protein_g' => $data['protein_g'] !== '' ? (float) $data['protein_g'] : null,
            'carbs_g' => $data['carbs_g'] !== '' ? (float) $data['carbs_g'] : null,
            'fat_g' => $data['fat_g'] !== '' ? (float) $data['fat_g'] : null,
            'notes' => $data['notes'] ?: null,
            'source' => 'manual',
        ];

        if ($this->id !== null) {
            FoodEntry::findOrFail($this->id)->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) FoodEntry::create($payload)->id;
        }

        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return FoodEntry::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.food-entry-form');
    }
}
