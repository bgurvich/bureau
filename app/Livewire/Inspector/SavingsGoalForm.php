<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\SavingsGoal;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted SavingsGoal form. Not tracked by the admin panel
 * (adminModelMap has no entry for savings_goal), so the extraction is a
 * straight state + mount + save just like TimeEntryForm.
 */
class SavingsGoalForm extends Component
{
    public ?int $id = null;

    public string $savings_name = '';

    public string $savings_target_amount = '';

    public string $savings_target_date = '';

    public string $savings_starting_amount = '0';

    public string $savings_saved_amount = '0';

    public string $savings_currency = 'USD';

    public string $savings_state = 'active';

    public ?int $savings_account_id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $g = SavingsGoal::findOrFail($id);
            $this->savings_name = (string) $g->name;
            $this->savings_target_amount = (string) $g->target_amount;
            $this->savings_target_date = $g->target_date ? $g->target_date->toDateString() : '';
            $this->savings_starting_amount = (string) $g->starting_amount;
            $this->savings_saved_amount = (string) $g->saved_amount;
            $this->savings_currency = (string) $g->currency;
            $this->savings_state = (string) $g->state;
            $this->savings_account_id = $g->account_id;
        } else {
            $household = CurrentHousehold::get();
            $this->savings_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'savings_name' => 'required|string|max:120',
            'savings_target_amount' => 'required|numeric|min:0',
            'savings_target_date' => 'nullable|date',
            'savings_starting_amount' => 'nullable|numeric|min:0',
            'savings_saved_amount' => 'nullable|numeric|min:0',
            'savings_currency' => 'required|string|size:3|alpha',
            'savings_state' => ['required', Rule::in(['active', 'paused', 'achieved', 'abandoned'])],
            'savings_account_id' => 'nullable|integer|exists:accounts,id',
        ]);

        $payload = [
            'name' => $data['savings_name'],
            'target_amount' => (float) $data['savings_target_amount'],
            'target_date' => $data['savings_target_date'] ?: null,
            'starting_amount' => (float) ($data['savings_starting_amount'] ?? 0),
            'saved_amount' => (float) ($data['savings_saved_amount'] ?? 0),
            'currency' => strtoupper($data['savings_currency']),
            'state' => $data['savings_state'],
            'account_id' => $data['savings_account_id'] ?: null,
        ];

        if ($this->id !== null) {
            SavingsGoal::findOrFail($this->id)->forceFill($payload)->save();
        } else {
            $this->id = (int) SavingsGoal::forceCreate($payload)->id;
        }

        $this->dispatch('inspector-saved', type: 'savings_goal', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'savings_goal', id: $this->id);
    }

    public function render(): View
    {
        return view('livewire.inspector.savings-goal-form');
    }
}
