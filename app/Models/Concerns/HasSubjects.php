<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for any record that accumulates polymorphic "subjects" via
 * a pivot table — currently Task (task_subjects) and Note (note_subjects).
 *
 * The implementing class declares `protected string $subjectsTable` naming
 * the pivot. The foreign key column on the pivot is derived from the model's
 * foreign-key name ({table}_id style, e.g. tasks → task_id).
 *
 * Polymorphic M:M doesn't map to a single Eloquent relation because the
 * target side is heterogeneous. `subjects()` returns a flat Collection of
 * live models from multiple tables, loaded in batches grouped by morph type.
 *
 * @property-read Collection<int, Model> $subjects
 */
trait HasSubjects
{
    /** Returns the pivot table name; concrete models override. */
    abstract protected function subjectsTable(): string;

    /** Returns the foreign-key column on the pivot (e.g. 'task_id'). */
    abstract protected function subjectsForeignKey(): string;

    /**
     * @return Collection<int, Model>
     */
    public function subjects(): Collection
    {
        $rows = DB::table($this->subjectsTable())
            ->where($this->subjectsForeignKey(), $this->getKey())
            ->orderBy('position')
            ->get(['subject_type', 'subject_id', 'position']);

        // Preserve pivot order in the final collection. Load per-type in
        // batches for efficiency, then re-interleave by the original order.
        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row->subject_type][] = (int) $row->subject_id;
        }

        /** @var array<string, array<int, Model>> $loadedByType  type => [id => model] */
        $loadedByType = [];
        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class)) {
                continue;
            }
            /** @var class-string<Model> $class */
            $loadedByType[$type] = $class::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
        }

        /** @var Collection<int, Model> $out */
        $out = new Collection;
        foreach ($rows as $row) {
            $hit = $loadedByType[(string) $row->subject_type][(int) $row->subject_id] ?? null;
            if ($hit) {
                $out->push($hit);
            }
        }

        return $out;
    }

    /**
     * Replace the pivot rows with the supplied subject refs. The refs' order
     * is preserved in the position column so the UI can show them in the
     * user's chosen sequence.
     *
     * @param  array<int, array{type: string, id: int}>  $refs
     */
    public function syncSubjects(array $refs): void
    {
        $pairs = [];
        foreach ($refs as $ref) {
            if (! is_array($ref) || ! is_string($ref['type'] ?? null) || ! is_numeric($ref['id'] ?? null)) {
                continue;
            }
            $type = $this->normalizeMorphType($ref['type']);
            if ($type === null) {
                continue;
            }
            // Dedup while preserving first-seen order.
            $key = $type.':'.(int) $ref['id'];
            if (! isset($pairs[$key])) {
                $pairs[$key] = [
                    'subject_type' => $type,
                    'subject_id' => (int) $ref['id'],
                ];
            }
        }

        DB::transaction(function () use ($pairs) {
            DB::table($this->subjectsTable())
                ->where($this->subjectsForeignKey(), $this->getKey())
                ->delete();
            if ($pairs === []) {
                return;
            }
            $rows = [];
            $position = 0;
            foreach ($pairs as $p) {
                $rows[] = array_merge($p, [
                    $this->subjectsForeignKey() => $this->getKey(),
                    'position' => $position++,
                ]);
            }
            DB::table($this->subjectsTable())->insert($rows);
        });
    }

    /**
     * Accept either a class-string, a morph alias, or a lowercase kind
     * ('vehicle' → 'App\\Models\\Vehicle'). Returns the canonical form the
     * pivot stores — aligned with Eloquent's morph map when set, else the
     * fully-qualified class.
     */
    private function normalizeMorphType(string $raw): ?string
    {
        if (class_exists($raw)) {
            $fromMap = array_search($raw, Relation::morphMap() ?: [], true);

            return $fromMap === false ? $raw : $fromMap;
        }
        $fromMap = Relation::getMorphedModel($raw);
        if ($fromMap !== null) {
            return $raw;
        }
        // Allow short kind aliases like 'vehicle' → App\Models\Vehicle
        $candidate = 'App\\Models\\'.ucfirst($raw);

        return class_exists($candidate) ? $candidate : null;
    }
}
