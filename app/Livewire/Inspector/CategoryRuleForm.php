<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\WithCategoryPicker;
use App\Models\CategoryRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted CategoryRule form. Uses WithCategoryPicker for the
 * inline-create category select.
 */
class CategoryRuleForm extends Component
{
    use WithCategoryPicker;

    public ?int $id = null;

    public ?int $rule_category_id = null;

    public string $rule_pattern_type = 'contains';

    public string $rule_pattern = '';

    public int $rule_priority = 100;

    public bool $rule_active = true;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $r = CategoryRule::findOrFail($id);
            $this->rule_category_id = $r->category_id;
            $this->rule_pattern_type = (string) $r->pattern_type;
            $this->rule_pattern = (string) $r->pattern;
            $this->rule_priority = (int) $r->priority;
            $this->rule_active = (bool) $r->active;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'rule_category_id' => 'required|integer|exists:categories,id',
            'rule_pattern_type' => ['required', Rule::in(['contains', 'regex'])],
            'rule_pattern' => 'required|string|max:500',
            'rule_priority' => 'integer|min:0|max:1000',
            'rule_active' => 'boolean',
        ]);

        $payload = [
            'category_id' => $data['rule_category_id'],
            'pattern_type' => $data['rule_pattern_type'],
            'pattern' => $data['rule_pattern'],
            'priority' => (int) ($data['rule_priority'] ?? 100),
            'active' => $data['rule_active'],
        ];

        if ($this->id !== null) {
            CategoryRule::findOrFail($this->id)->forceFill($payload)->save();
        } else {
            $this->id = (int) CategoryRule::forceCreate($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'category_rule', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'category_rule', id: $this->id);
    }

    protected function defaultCategoryPickerModel(): string
    {
        return 'rule_category_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.category-rule-form');
    }
}
