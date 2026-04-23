<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\Reminder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Reminder form. Not in adminModelMap, so no admin panel
 * interaction — straight state + mount + save.
 */
class ReminderForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public string $reminder_title = '';

    public string $reminder_remind_at = '';

    public string $reminder_channel = 'in_app';

    public string $reminder_state = 'pending';

    public string $reminder_notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $r = Reminder::findOrFail($id);
            $this->reminder_title = (string) ($r->title ?? '');
            $this->reminder_remind_at = $r->remind_at ? $r->remind_at->format('Y-m-d\TH:i') : '';
            $this->reminder_channel = (string) ($r->channel ?? 'in_app');
            $this->reminder_state = (string) ($r->state ?? 'pending');
            $this->reminder_notes = (string) ($r->notes ?? '');
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'reminder_title' => 'required|string|max:255',
            'reminder_remind_at' => 'required|date',
            'reminder_channel' => ['required', Rule::in(['in_app', 'email', 'slack', 'sms', 'telegram', 'push'])],
            'reminder_state' => ['required', Rule::in(['pending', 'fired', 'acknowledged', 'cancelled'])],
            'reminder_notes' => 'nullable|string|max:5000',
        ]);

        $payload = [
            'title' => $data['reminder_title'],
            'remind_at' => $data['reminder_remind_at'],
            'channel' => $data['reminder_channel'],
            'state' => $data['reminder_state'],
            'notes' => $data['reminder_notes'] ?: null,
        ];

        if ($this->id !== null) {
            Reminder::findOrFail($this->id)->update($payload);
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) Reminder::create($payload)->id;
        }

        $this->finalizeSave();
    }

    public function render(): View
    {
        return view('livewire.inspector.reminder-form');
    }
}
