<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Models\BodyMeasurement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Body composition — one row captures weight + fat% + muscle% at one
 * timestamp, matching how smart scales report. All three are optional:
 * a weight-only "I stepped on a regular scale" entry is valid. The
 * weight_unit switch just flips input interpretation; the DB always
 * holds kg.
 */
class BodyMeasurementForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;

    public ?int $id = null;

    public string $measured_at = '';

    public string $weight = '';

    public string $weight_unit = 'lb';

    public string $body_fat_pct = '';

    public string $muscle_pct = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;

        if ($id !== null) {
            $m = BodyMeasurement::findOrFail($id);
            $this->measured_at = $m->measured_at->format('Y-m-d\TH:i');
            // Preserve the unit preference per-edit: if the user has
            // been recording in lb, keep showing lb. Null weight means
            // the row never had a kg value; fall back to the session
            // default (lb for this user base).
            if ($m->weight_kg !== null) {
                $this->weight_unit = 'lb';
                $this->weight = number_format((float) $m->weightLb(), 1, '.', '');
            }
            $this->body_fat_pct = $m->body_fat_pct !== null ? (string) $m->body_fat_pct : '';
            $this->muscle_pct = $m->muscle_pct !== null ? (string) $m->muscle_pct : '';
            $this->notes = (string) ($m->notes ?? '');
            $this->loadAdminMeta();
        } else {
            $this->measured_at = now()->format('Y-m-d\TH:i');
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        // Empty-row guard — at least one metric must be present. Done
        // here (not as a validate rule) because Laravel's `nullable`
        // short-circuits closure rules when the field is blank, which
        // is exactly the case we want to catch.
        $allEmpty = $this->weight === ''
            && $this->body_fat_pct === ''
            && $this->muscle_pct === '';
        if ($allEmpty) {
            $this->addError('weight', __('Record at least one of weight, body fat %, or muscle %.'));

            return;
        }

        $data = $this->validate([
            'measured_at' => 'required|date',
            'weight' => 'nullable|numeric|min:0|max:1000',
            'weight_unit' => 'required|in:lb,kg',
            'body_fat_pct' => 'nullable|numeric|min:0|max:80',
            'muscle_pct' => 'nullable|numeric|min:0|max:80',
            'notes' => 'nullable|string|max:5000',
        ]);

        $weightKg = null;
        if (($data['weight'] ?? '') !== '') {
            $raw = (float) $data['weight'];
            $weightKg = $data['weight_unit'] === 'kg' ? $raw : $raw / 2.20462;
        }

        $payload = [
            'measured_at' => $data['measured_at'],
            'weight_kg' => $weightKg,
            'body_fat_pct' => ($data['body_fat_pct'] ?? '') !== '' ? (float) $data['body_fat_pct'] : null,
            'muscle_pct' => ($data['muscle_pct'] ?? '') !== '' ? (float) $data['muscle_pct'] : null,
            'notes' => ($data['notes'] ?? '') ?: null,
        ];

        if ($this->id !== null) {
            BodyMeasurement::findOrFail($this->id)->update($payload);
            $this->persistAdminOwner();
        } else {
            $payload['user_id'] = auth()->id();
            $this->id = (int) BodyMeasurement::create($payload)->id;
        }

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return BodyMeasurement::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.body-measurement-form');
    }
}
