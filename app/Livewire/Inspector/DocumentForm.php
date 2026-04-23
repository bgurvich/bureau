<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasPhotos;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Models\Document;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Document form. Covers passport / license / ID / will /
 * POA / insurance-card / other — kind + label + number + issuer +
 * issued/expires dates + an "in-case-of pack" flag that pulls the row
 * into the emergency bundle. Photos via HasPhotos since Document
 * uses HasMedia (each paper scan clips in as pivot role=photo).
 */
class DocumentForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasPhotos;
    use HasTagList;

    public ?int $id = null;

    public string $type = 'document';

    public string $doc_kind = 'passport';

    public string $doc_label = '';

    public string $doc_number = '';

    public string $doc_issuer = '';

    public string $doc_issued_on = '';

    public string $doc_expires_on = '';

    public bool $in_case_of_pack = false;

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $d = Document::findOrFail($id);
            $this->doc_kind = (string) $d->kind;
            $this->doc_label = (string) ($d->label ?? '');
            $this->doc_number = (string) ($d->number ?? '');
            $this->doc_issuer = (string) ($d->issuer ?? '');
            $this->doc_issued_on = $d->issued_on ? $d->issued_on->toDateString() : '';
            $this->doc_expires_on = $d->expires_on ? $d->expires_on->toDateString() : '';
            $this->in_case_of_pack = (bool) $d->in_case_of_pack;
            $this->notes = (string) ($d->notes ?? '');
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->doc_issued_on = now()->toDateString();
        }
    }

    #[On('inspector-save')]
    public function save(): void
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

        if ($this->id !== null) {
            Document::findOrFail($this->id)->update($payload);
        } else {
            $payload['holder_user_id'] = auth()->id();
            $this->id = (int) Document::create($payload)->id;
        }

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    protected function adminOwnerClass(): ?string
    {
        return Document::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'holder_user_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.document-form');
    }
}
