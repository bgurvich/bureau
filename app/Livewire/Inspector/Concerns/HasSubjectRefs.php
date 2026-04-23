<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Decision;
use App\Models\Document;
use App\Models\Goal;
use App\Models\HealthProvider;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OnlineAccount;
use App\Models\Project;
use App\Models\Property;
use App\Models\RecurringRule;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * Subject-refs repeater for inspector child components whose backing
 * model uses the `HasSubjects` trait (task, note, document, contract,
 * insurance, inventory, transaction). Exposes the same event surface
 * the original shell had — addSubject / removeSubject / moveSubjectTo
 * / reorderSubjects — plus the two computeds the Blade partials read
 * (subjectSearchResults + selectedSubjectsMeta). Persistence helpers
 * subjectRefsFrom() + parseSubjectRefs() mirror the shell versions.
 */
trait HasSubjectRefs
{
    /** @var array<int, string> */
    public array $subject_refs = [];

    public string $subject_search = '';

    /**
     * Short-kind → class map. Single source of truth for the "what can
     * be linked as a subject" list. Kept here (not on the consuming
     * class) so every subject-capable form picks from the same menu.
     *
     * @var array<string, class-string>
     */
    public const SUBJECT_KIND_MAP = [
        'vehicle' => Vehicle::class,
        'property' => Property::class,
        'contact' => Contact::class,
        'contract' => Contract::class,
        'inventory' => InventoryItem::class,
        'account' => Account::class,
        'project' => Project::class,
        'document' => Document::class,
        'health_provider' => HealthProvider::class,
        'online_account' => OnlineAccount::class,
        'recurring_rule' => RecurringRule::class,
        'journal_entry' => JournalEntry::class,
        'decision' => Decision::class,
        'goal' => Goal::class,
    ];

    /** @return array<int, array{ref: string, label: string, kind_label: string, name: string}> */
    #[Computed]
    public function subjectSearchResults(): array
    {
        $q = trim($this->subject_search);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $term = '%'.$q.'%';
        $already = array_flip($this->subject_refs);
        $out = [];

        foreach (self::SUBJECT_KIND_MAP as $kind => $class) {
            $kindLabel = $this->subjectKindLabel($kind);
            $nameCol = $this->subjectNameColumn($class);

            /** @var Collection<int, Model> $rows */
            $rows = $class::query()
                ->where($nameCol, 'like', $term)
                ->orderBy($nameCol)
                ->limit(5)
                ->get();

            foreach ($rows as $row) {
                $ref = $kind.':'.$row->getKey();
                if (isset($already[$ref])) {
                    continue;
                }
                $name = (string) ($row->getAttribute($nameCol) ?? '#'.$row->getKey());
                $out[] = [
                    'ref' => $ref,
                    'label' => $kindLabel.' · '.$name,
                    'kind_label' => $kindLabel,
                    'name' => $name,
                ];
                if (count($out) >= 20) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{label: string, kind_label: string}> */
    #[Computed]
    public function selectedSubjectsMeta(): array
    {
        if ($this->subject_refs === []) {
            return [];
        }

        $byKind = [];
        foreach ($this->subject_refs as $ref) {
            if (! is_string($ref) || ! str_contains($ref, ':')) {
                continue;
            }
            [$kind, $id] = explode(':', $ref, 2);
            if (! isset(self::SUBJECT_KIND_MAP[$kind]) || ! is_numeric($id)) {
                continue;
            }
            $byKind[$kind][] = (int) $id;
        }

        $out = [];
        foreach ($byKind as $kind => $ids) {
            $class = self::SUBJECT_KIND_MAP[$kind];
            $nameCol = $this->subjectNameColumn($class);
            $kindLabel = $this->subjectKindLabel($kind);

            /** @var Collection<int, Model> $rows */
            $rows = $class::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $row = $rows->get($id);
                $name = $row ? (string) ($row->getAttribute($nameCol) ?? '#'.$id) : __('(deleted)');
                $out[$kind.':'.$id] = [
                    'label' => $kindLabel.' · '.$name,
                    'kind_label' => $kindLabel,
                ];
            }
        }

        return $out;
    }

    public function addSubject(string $ref): void
    {
        if (! str_contains($ref, ':')) {
            return;
        }
        if (in_array($ref, $this->subject_refs, true)) {
            return;
        }
        [$kind] = explode(':', $ref, 2);
        if (! isset(self::SUBJECT_KIND_MAP[$kind])) {
            return;
        }
        $this->subject_refs[] = $ref;
        $this->subject_search = '';
    }

    public function removeSubject(string $ref): void
    {
        $this->subject_refs = array_values(array_diff($this->subject_refs, [$ref]));
    }

    public function moveSubjectTo(string $ref, int $newIndex): void
    {
        $idx = array_search($ref, $this->subject_refs, true);
        if ($idx === false) {
            return;
        }
        $item = $this->subject_refs[$idx];
        array_splice($this->subject_refs, $idx, 1);
        $newIndex = max(0, min($newIndex, count($this->subject_refs)));
        array_splice($this->subject_refs, $newIndex, 0, [$item]);
        $this->subject_refs = array_values($this->subject_refs);
    }

    /**
     * @param  array<int, string>  $orderedRefs
     */
    public function reorderSubjects(array $orderedRefs): void
    {
        if ($this->subject_refs === []) {
            return;
        }

        $existing = array_flip($this->subject_refs);
        $next = [];
        foreach ($orderedRefs as $ref) {
            $r = (string) $ref;
            if (isset($existing[$r])) {
                $next[] = $r;
                unset($existing[$r]);
            }
        }
        foreach (array_keys($existing) as $leftover) {
            $next[] = $leftover;
        }

        $this->subject_refs = $next;
    }

    /** @return array<int, string> */
    protected function subjectRefsFrom(Model $model): array
    {
        if (! method_exists($model, 'subjects')) {
            return [];
        }
        $classToKind = array_flip(self::SUBJECT_KIND_MAP);
        $refs = [];
        foreach (call_user_func([$model, 'subjects']) as $row) {
            $kind = $classToKind[get_class($row)] ?? null;
            if ($kind === null) {
                continue;
            }
            $refs[] = $kind.':'.$row->getKey();
        }

        return $refs;
    }

    /**
     * @param  array<int, string>  $refs
     * @return array<int, array{type: string, id: int}>
     */
    protected function parseSubjectRefs(array $refs): array
    {
        $out = [];
        foreach ($refs as $r) {
            if (! is_string($r) || ! str_contains($r, ':')) {
                continue;
            }
            [$kind, $id] = explode(':', $r, 2);
            $class = self::SUBJECT_KIND_MAP[$kind] ?? null;
            if ($class === null || ! is_numeric($id)) {
                continue;
            }
            $out[] = ['type' => $class, 'id' => (int) $id];
        }

        return $out;
    }

    private function subjectKindLabel(string $kind): string
    {
        return match ($kind) {
            'vehicle' => __('Vehicle'),
            'property' => __('Property'),
            'contact' => __('Contact'),
            'contract' => __('Contract'),
            'inventory' => __('Inventory'),
            'account' => __('Account'),
            'project' => __('Project'),
            'document' => __('Document'),
            'health_provider' => __('Health provider'),
            'online_account' => __('Online account'),
            'recurring_rule' => __('Bill'),
            'journal_entry' => __('Journal entry'),
            'decision' => __('Decision'),
            'goal' => __('Goal'),
            default => ucfirst($kind),
        };
    }

    /** @param  class-string  $class */
    private function subjectNameColumn(string $class): string
    {
        return match ($class) {
            Vehicle::class => 'model',
            Property::class => 'name',
            Contact::class => 'display_name',
            Contract::class => 'title',
            InventoryItem::class => 'name',
            Account::class => 'name',
            Project::class => 'name',
            Document::class => 'label',
            HealthProvider::class => 'name',
            OnlineAccount::class => 'service_name',
            RecurringRule::class => 'title',
            JournalEntry::class => 'title',
            Decision::class => 'title',
            Goal::class => 'title',
            default => 'name',
        };
    }
}
