<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\Tag;
use App\Models\TagRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted TagRule form. Uses its own tagPickerOptions computed (the
 * picker is simpler than category — no inline create, so we don't need a
 * shared trait yet).
 */
class TagRuleForm extends Component
{
    public ?int $id = null;

    public ?int $tag_rule_tag_id = null;

    public string $tag_rule_pattern_type = 'contains';

    public string $tag_rule_pattern = '';

    public int $tag_rule_priority = 100;

    public bool $tag_rule_active = true;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $r = TagRule::findOrFail($id);
            $this->tag_rule_tag_id = $r->tag_id;
            $this->tag_rule_pattern_type = (string) $r->pattern_type;
            $this->tag_rule_pattern = (string) $r->pattern;
            $this->tag_rule_priority = (int) $r->priority;
            $this->tag_rule_active = (bool) $r->active;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'tag_rule_tag_id' => 'required|integer|exists:tags,id',
            'tag_rule_pattern_type' => ['required', Rule::in(['contains', 'regex'])],
            'tag_rule_pattern' => 'required|string|max:500',
            'tag_rule_priority' => 'integer|min:0|max:1000',
            'tag_rule_active' => 'boolean',
        ]);

        $payload = [
            'tag_id' => $data['tag_rule_tag_id'],
            'pattern_type' => $data['tag_rule_pattern_type'],
            'pattern' => $data['tag_rule_pattern'],
            'priority' => (int) ($data['tag_rule_priority'] ?? 100),
            'active' => $data['tag_rule_active'],
        ];

        if ($this->id !== null) {
            TagRule::findOrFail($this->id)->forceFill($payload)->save();
        } else {
            $this->id = (int) TagRule::forceCreate($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'tag_rule', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'tag_rule', id: $this->id);
    }

    /** @return array<int, string> */
    #[Computed]
    public function tagPickerOptions(): array
    {
        return Tag::orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        return view('livewire.inspector.tag-rule-form');
    }
}
