<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\BudgetCap;
use App\Models\Category;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted BudgetCap form. Not tracked by the admin panel. Needs its
 * own categoryPickerOptions + createCategoryInline for the inline-create
 * searchable-select (same behavior as the shell's shared helpers, just
 * scoped to this child component so the select's events land on it).
 */
class BudgetCapForm extends Component
{
    public ?int $id = null;

    public ?int $budget_category_id = null;

    public string $budget_monthly_cap = '';

    public string $budget_currency = 'USD';

    public bool $budget_active = true;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $c = BudgetCap::findOrFail($id);
            $this->budget_category_id = $c->category_id;
            $this->budget_monthly_cap = (string) $c->monthly_cap;
            $this->budget_currency = (string) $c->currency;
            $this->budget_active = (bool) $c->active;
        } else {
            $household = CurrentHousehold::get();
            $this->budget_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'budget_category_id' => 'required|integer|exists:categories,id',
            'budget_monthly_cap' => 'required|numeric|min:0',
            'budget_currency' => 'required|string|size:3|alpha',
            'budget_active' => 'boolean',
        ]);

        $payload = [
            'category_id' => $data['budget_category_id'],
            'monthly_cap' => (float) $data['budget_monthly_cap'],
            'currency' => strtoupper($data['budget_currency']),
            'active' => $data['budget_active'],
        ];

        if ($this->id !== null) {
            BudgetCap::findOrFail($this->id)->forceFill($payload)->save();
        } else {
            $this->id = (int) BudgetCap::forceCreate($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'budget_cap', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'budget_cap', id: $this->id);
    }

    /** @return array<int, string> */
    #[Computed]
    public function categoryPickerOptions(): array
    {
        return Category::orderBy('name')->pluck('name', 'id')->all();
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

        $label = ucfirst($category->kind).' · '.$category->name;
        $this->dispatch('ss-option-added', model: $modelKey ?: 'budget_category_id', id: $category->id, label: $label);
    }

    public function render(): View
    {
        return view('livewire.inspector.budget-cap-form');
    }
}
