<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Contact;
use App\Models\Media;
use App\Models\Transaction;
use App\Support\CategorySourceMatcher;
use App\Support\CurrentHousehold;
use App\Support\DescriptionNormalizer;
// App\Support\Formatting is referenced only from the template section
// via \App\Support\Formatting::money(...) — kept out of the `use` list
// so Pint's no_unused_imports fixer doesn't strip it on every run.
use App\Support\MediaFolders;
use App\Support\ProjectionMatcher;
use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParserRegistry;
use App\Support\TransferPairing;
use App\Support\VendorReresolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bulk statement intake: upload multiple PDF/CSV exports, review per-file,
 * import selected rows into Transactions. Two dedup guardrails enforce
 * idempotence across re-uploads and cross-source conflicts:
 *   - File-level: media.hash lookup per household.
 *   - Row-level: deterministic external_id on each Transaction.
 */
new
#[Layout('components.layouts.app', ['title' => 'Import statements'])]
class extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    /**
     * Parsed-file state keyed by stable file_id (uuid per upload slot).
     *   [file_id => [
     *      'name' => string,
     *      'status' => 'parsing'|'ready'|'already_imported'|'unrecognized',
     *      'hash' => ?string,
     *      'bank_slug' => ?string, 'bank_label' => ?string,
     *      'import_source' => ?string, 'account_last4' => ?string,
     *      'period_start' => ?string, 'period_end' => ?string,
     *      'opening' => ?float, 'closing' => ?float,
     *      'rows' => array<int, array{occurred_on: string, description: string, amount: float}>,
     *      'disk_path' => ?string,  // where we saved the file
     *      'mime' => ?string,
     *      'size' => ?int,
     *      'media_id' => ?int,      // set after import
     *      'prev_imported_count' => int, // for already_imported state
     *      'created_count' => int,       // populated after import
     *      'error' => ?string,
     *   ]]
     *
     * @var array<string, array<string, mixed>>
     */
    public array $parsed = [];

    /** file_id → account_id */
    public array $accountFor = [];

    /**
     * Default account applied to every file parsed after it's set. Saves the
     * tedium of picking the same account on each card when the batch is all
     * from one source (the common case — a user exports a quarter from one
     * bank and drops all four statements at once).
     */
    public ?int $defaultAccountId = null;

    /**
     * Global year override — applied to every file that doesn't carry its
     * own explicit year. Bank PDFs that omit the statement-period header
     * fall back to the current year by default, which imports January
     * rows into the wrong calendar year; this input lets the user pin the
     * right year for the whole batch in one place.
     */
    public ?int $globalYear = null;

    /** file_id → year override (takes precedence over globalYear). */
    public array $yearFor = [];

    /** file_id → [rowIndex => bool selected] */
    public array $selected = [];

    /** file_id → [rowIndex => bool duplicate] */
    public array $duplicates = [];

    public ?string $bulkMessage = null;

    /** Surface recent upload-level problems (e.g. some files silently dropped). */
    public ?string $uploadError = null;

    /**
     * PHP's default max_file_uploads is 20; nginx/php-fpm silently truncate
     * anything beyond that on a single multipart form. Cap in the client
     * flow so the user gets a loud warning instead of "verification screens
     * for the files that made it, nothing for the rest."
     */
    private const MAX_FILES_PER_BATCH = 20;

    /** Per-file ceiling — bank statements are almost always <10 MB. */
    private const MAX_BYTES_PER_FILE = 20 * 1024 * 1024;

    public function updatedFiles(): void
    {
        $this->uploadError = null;

        $incoming = array_values(array_filter($this->files ?? []));
        if (count($incoming) > self::MAX_FILES_PER_BATCH) {
            $dropped = count($incoming) - self::MAX_FILES_PER_BATCH;
            $this->uploadError = __(':n extra file(s) ignored — upload at most :max at a time.', [
                'n' => $dropped, 'max' => self::MAX_FILES_PER_BATCH,
            ]);
            $incoming = array_slice($incoming, 0, self::MAX_FILES_PER_BATCH);
        }

        foreach ($incoming as $file) {
            $name = (string) $file->getClientOriginalName();
            try {
                $mime = (string) $file->getMimeType();
                $size = (int) $file->getSize();

                if ($size > self::MAX_BYTES_PER_FILE) {
                    $this->parsed[(string) Str::uuid()] = [
                        'name' => $name,
                        'status' => 'failed',
                        'error' => __('File too large (:mb MB > :max MB).', [
                            'mb' => number_format($size / 1024 / 1024, 1),
                            'max' => self::MAX_BYTES_PER_FILE / 1024 / 1024,
                        ]),
                        'rows' => [],
                    ];

                    continue;
                }

                $bytes = @file_get_contents($file->getRealPath());
                if ($bytes === false) {
                    $this->parsed[(string) Str::uuid()] = [
                        'name' => $name,
                        'status' => 'failed',
                        'error' => __('Could not read the uploaded file. Re-upload or check file permissions.'),
                        'rows' => [],
                    ];

                    continue;
                }

                if ($this->looksLikeZip($name, $mime, $bytes)) {
                    $this->unpackZip($bytes);

                    continue;
                }

                $fileId = (string) Str::uuid();
                $this->parseBytes($fileId, $name, $mime, $bytes);
            } catch (Throwable $e) {
                // Never let one broken upload abort the rest of the batch — log
                // the exception for the operator and surface a short error on
                // the file tile. User gets a clear failure instead of a silent
                // empty verification screen.
                Log::warning('Statement import failed for one file', [
                    'name' => $name, 'error' => $e->getMessage(),
                ]);
                $this->parsed[(string) Str::uuid()] = [
                    'name' => $name,
                    'status' => 'failed',
                    'error' => __('Upload failed: :msg', ['msg' => $e->getMessage()]),
                    'rows' => [],
                ];
            }
        }
        $this->files = [];  // consume uploads so the same file isn't re-parsed
    }

    private function looksLikeZip(string $name, string $mime, string $bytes): bool
    {
        if (str_ends_with(strtolower($name), '.zip')) {
            return true;
        }
        if ($mime === 'application/zip' || $mime === 'application/x-zip-compressed') {
            return true;
        }

        return str_starts_with($bytes, "PK\x03\x04");
    }

    /**
     * Unpack inner PDFs/CSVs from the archive and feed each through the
     * parser like any direct upload. Guarded against zip bombs: caps inner
     * file count and total extracted size.
     */
    private function unpackZip(string $zipBytes): void
    {
        $maxInnerFiles = 50;
        $maxTotalBytes = 100 * 1024 * 1024; // 100 MiB

        $tmp = tempnam(sys_get_temp_dir(), 'secretaire-zip-');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, $zipBytes);

        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            $this->parsed[(string) Str::uuid()] = [
                'name' => 'archive.zip',
                'status' => 'unrecognized',
                'error' => __('Could not open zip archive.'),
                'rows' => [],
            ];

            return;
        }

        $totalBytes = 0;
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if ($count >= $maxInnerFiles || $totalBytes >= $maxTotalBytes) {
                break;
            }
            $entry = $zip->statIndex($i);
            if (! $entry) {
                continue;
            }
            $innerName = (string) $entry['name'];
            if (str_ends_with($innerName, '/')) {
                continue;   // directory
            }
            // Skip macOS metadata noise.
            if (str_contains($innerName, '__MACOSX/') || basename($innerName) === '.DS_Store') {
                continue;
            }
            $ext = strtolower(pathinfo($innerName, PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'csv', 'tsv'], true)) {
                continue;
            }
            $innerBytes = $zip->getFromIndex($i);
            if ($innerBytes === false) {
                continue;
            }
            $totalBytes += strlen($innerBytes);
            $count++;
            $mime = $ext === 'pdf' ? 'application/pdf' : 'text/csv';
            $fileId = (string) Str::uuid();
            $this->parseBytes($fileId, basename($innerName), $mime, $innerBytes);
        }

        $zip->close();
        @unlink($tmp);
    }

    /**
     * Parse a file's raw bytes (from either a direct upload or a zip inner
     * entry). Computes hash, runs dedup check, persists to disk, parses.
     */
    private function parseBytes(string $fileId, string $name, string $mime, string $bytes): void
    {
        $hash = hash('sha256', $bytes);
        $householdId = CurrentHousehold::get()?->id;

        // File-level dedup: have we seen this hash before in this household?
        // When yes AND the resulting transactions are still on file, we
        // no longer short-circuit. Instead the normal parse path runs,
        // and the row-level dedup at recomputeDuplicates() auto-deselects
        // whatever is already in the DB so the user can import the delta
        // (missing rows the parser now extracts that weren't there before,
        // transactions the user had deleted, etc.). Any transactions the
        // import creates attach to the EXISTING Media row via
        // ensureMediaForFile's media_id short-circuit.
        $existingMedia = Media::where('hash', $hash)
            ->when($householdId, fn ($q) => $q->where('household_id', $householdId))
            ->first();
        $prevImportedCount = 0;
        $reuseMediaId = null;
        if ($existingMedia) {
            $prevImportedCount = Transaction::whereHas('media', fn ($q) => $q->where('media.id', $existingMedia->id))
                ->count();
            if ($prevImportedCount > 0) {
                // Re-scan path: reuse the existing Media row + file on
                // disk; let the normal parse + dedup flow surface the
                // delta. No status='already_imported' short-circuit.
                $reuseMediaId = (int) $existingMedia->id;
            } else {
                // Orphan: the Media row lingered but every Transaction
                // it was attached to has since been deleted (manual DB
                // wipe, household reset). Drop the stale Media + its
                // on-disk file so the normal path below recreates both
                // cleanly.
                try {
                    Storage::disk((string) $existingMedia->disk)->delete((string) $existingMedia->path);
                } catch (Throwable) {
                    // Best-effort — the file may already be gone on disk.
                }
                $existingMedia->delete();
            }
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        // Re-scan path reuses the existing Media's file on disk so we
        // don't leave orphan copies under two paths for the same hash.
        if ($reuseMediaId !== null && $existingMedia !== null && Storage::disk((string) $existingMedia->disk)->exists((string) $existingMedia->path)) {
            $path = (string) $existingMedia->path;
        } else {
            $path = 'statements/'.($householdId ?? 0).'/'.date('Y/m').'/'.$fileId.'.'.$ext;
            try {
                Storage::disk('local')->put($path, $bytes);
            } catch (Throwable $e) {
                Log::warning('Statement storage write failed', ['name' => $name, 'error' => $e->getMessage()]);
                $this->parsed[$fileId] = [
                    'name' => $name,
                    'status' => 'failed',
                    'error' => __('Could not save file to disk. Check storage permissions.'),
                    'hash' => $hash,
                    'rows' => [],
                ];

                return;
            }
        }

        $registry = app(ParserRegistry::class);
        $stored = Storage::disk('local')->path($path);

        try {
            $statement = $registry->parseFile($stored);
        } catch (Throwable $e) {
            Log::warning('Statement parser crashed', ['name' => $name, 'error' => $e->getMessage()]);
            $this->parsed[$fileId] = [
                'name' => $name,
                'status' => 'failed',
                'error' => __('Parser error: :msg', ['msg' => $e->getMessage()]),
                'hash' => $hash,
                'disk_path' => $path,
                'mime' => $mime,
                'size' => strlen($bytes),
                'rows' => [],
            ];

            return;
        }

        if (! $statement instanceof ParsedStatement) {
            $this->parsed[$fileId] = [
                'name' => $name,
                'status' => 'unrecognized',
                'hash' => $hash,
                'disk_path' => $path,
                'mime' => $mime,
                'size' => strlen($bytes),
                'rows' => [],
            ];

            return;
        }

        $rows = [];
        foreach ($statement->transactions as $i => $t) {
            $rows[$i] = [
                'occurred_on' => $t->occurredOn->toDateString(),
                'description' => $t->description,
                'amount' => $t->amount,
                'closing_balance' => $t->runningBalance,
                'check_number' => $t->checkNumber,
                // Source-supplied category label (Costco's Category column,
                // etc.). Matched at createTransactions() time against
                // categories.match_patterns — kept here so the preview UI
                // can surface the mapping before import.
                'category_hint' => $t->categoryHint,
                // Per-row edits the user can apply in the preview before
                // hitting Import. `counterparty_id_override` wins over the
                // auto-detect path; `match_pattern` is the *user's* edit
                // and stays empty until they type — importAll falls back
                // to the row's fingerprint when creating a new vendor, so
                // a blank here still produces a reasonable default. Do
                // NOT pre-seed with the fingerprint: doing so pollutes
                // patternOverrides in buildContactMap, letting the first
                // row's auto-value clobber a later row's explicit edit.
                'counterparty_id_override' => null,
                'match_pattern' => '',
            ];
        }

        // Parser recognised the file but extracted zero rows. The old code fell
        // through to `status=ready` with an empty table — which reads as "the
        // import worked, just nothing in it." That's the bug: the user sees
        // empty verification screens instead of a failure. Surface it loudly.
        if ($rows === []) {
            $this->parsed[$fileId] = [
                'name' => $name,
                'status' => 'failed',
                'error' => __('Parser recognised :bank but extracted 0 transactions — the file may be password-protected, truncated, or use an unsupported layout.', [
                    'bank' => $statement->bankLabel ?? __('statement'),
                ]),
                'hash' => $hash,
                'disk_path' => $path,
                'mime' => $mime,
                'size' => strlen($bytes),
                'rows' => [],
            ];

            return;
        }

        // Year resolution (best-effort, user can override via the year
        // input on the card or the global input at the top):
        //   1. statement's own periodEnd year, when the parser caught
        //      a "Statement Period ..." header — most reliable.
        //   2. a 4-digit year in the filename, e.g. "Chase-2024-01.pdf".
        //   3. current calendar year as last resort.
        $detectedYear = $statement->periodEnd?->year
            ?? $this->yearFromFilename($name)
            ?? (int) date('Y');

        $this->parsed[$fileId] = [
            'name' => $name,
            'status' => 'ready',
            'hash' => $hash,
            'bank_slug' => $statement->bankSlug,
            'bank_label' => $statement->bankLabel,
            'import_source' => $statement->importSource(),
            'account_last4' => $statement->accountLast4,
            'period_start' => $statement->periodStart?->toDateString(),
            'period_end' => $statement->periodEnd?->toDateString(),
            'opening' => $statement->openingBalance,
            'closing' => $statement->closingBalance,
            'detected_year' => $detectedYear,
            'rows' => $rows,
            'disk_path' => $path,
            'mime' => $mime,
            'size' => strlen($bytes),
            // When set, ensureMediaForFile() short-circuits to this id
            // instead of creating a fresh Media — reuses the existing
            // pivot so re-imports don't fork the statement's scan rows
            // across two Media records.
            'media_id' => $reuseMediaId,
            // Count of transactions already attached to the reused Media
            // row before this re-scan started. Zero on fresh imports.
            // Preview renders an info chip when > 0.
            'prev_imported_count' => $prevImportedCount,
        ];

        // Default all rows selected — dedup computation lands once account picked.
        $this->selected[$fileId] = array_fill(0, count($rows), true);
        $this->duplicates[$fileId] = array_fill(0, count($rows), false);

        // Apply the effective year to the rows immediately so the review
        // table renders the corrected dates. User edits flow through the
        // same path via setYearForFile / updatedGlobalYear.
        $this->applyYearForFile($fileId);

        // Pre-apply the session's default account so the user doesn't have to
        // pick it on every card when dropping a batch from the same source.
        // setAccount() also triggers recomputeDuplicates(), so dup flags land
        // immediately without waiting for a manual change event.
        if ($this->defaultAccountId !== null) {
            $this->setAccount($fileId, $this->defaultAccountId);
        }
    }

    public function setAccount(string $fileId, ?int $accountId): void
    {
        $this->accountFor[$fileId] = $accountId;
        $this->recomputeDuplicates($fileId);
    }

    /**
     * Pick up the 4-digit year closest to the end of a filename — when
     * a user saves a batch as "Chase-2024-01.pdf" / "wf_q3_2024.pdf" the
     * year is almost always the statement's year. Matches only 20xx to
     * avoid catching an account number that happens to be 4 digits.
     */
    private function yearFromFilename(string $name): ?int
    {
        if (preg_match_all('/(20\d{2})/', $name, $m) && ! empty($m[1])) {
            return (int) end($m[1]);
        }

        return null;
    }

    /**
     * The year used for this file's rows: per-file override beats the
     * global input beats the detected year.
     */
    private function effectiveYear(string $fileId): ?int
    {
        if (! empty($this->yearFor[$fileId])) {
            return (int) $this->yearFor[$fileId];
        }
        if ($this->globalYear !== null) {
            return (int) $this->globalYear;
        }

        return $this->parsed[$fileId]['detected_year'] ?? null;
    }

    /**
     * Rewrite every row's occurred_on to use the effective year. Keeps
     * month + day from the parser's original date — the bank's month/
     * day is reliable, only the year is suspect in statements without
     * a period header.
     */
    private function applyYearForFile(string $fileId): void
    {
        $year = $this->effectiveYear($fileId);
        if ($year === null || empty($this->parsed[$fileId]['rows'])) {
            return;
        }
        foreach ($this->parsed[$fileId]['rows'] as $i => $row) {
            $date = $row['occurred_on'] ?? null;
            if (! is_string($date) || $date === '') {
                continue;
            }
            try {
                $carbon = CarbonImmutable::parse($date)->setYear($year);
                $this->parsed[$fileId]['rows'][$i]['occurred_on'] = $carbon->toDateString();
            } catch (Throwable) {
                // Leave the row alone — bad input upstream, the import
                // code will refuse to persist it anyway.
            }
        }
        $this->recomputeDuplicates($fileId);
    }

    public function setYearForFile(string $fileId, ?int $year): void
    {
        $this->yearFor[$fileId] = $year;
        $this->applyYearForFile($fileId);
    }

    /** Livewire hook — fires when the global year input changes. */
    public function updatedGlobalYear(mixed $value): void
    {
        $this->globalYear = ($value === '' || $value === null) ? null : (int) $value;
        foreach (array_keys($this->parsed) as $fileId) {
            // Per-file override wins; only files without one get the
            // global value propagated.
            if (empty($this->yearFor[$fileId])) {
                $this->applyYearForFile($fileId);
            }
        }
    }

    /**
     * Set the batch-level default account AND retroactively apply it to every
     * ready card that hasn't had an account picked yet. Lets the user drop a
     * batch, then pick the account once and have every card inherit it.
     */
    public function updatedDefaultAccountId(mixed $value): void
    {
        $accountId = $value === null || $value === '' ? null : (int) $value;
        $this->defaultAccountId = $accountId;

        if ($accountId === null) {
            return;
        }
        foreach ($this->parsed as $fileId => $state) {
            if (($state['status'] ?? null) !== 'ready') {
                continue;
            }
            if (empty($this->accountFor[$fileId])) {
                $this->setAccount((string) $fileId, $accountId);
            }
        }
    }

    /**
     * Row-level dedup: flag rows that overlap with existing Transactions on
     * the picked account.
     *
     * Two paths: exact (`external_id` = sha1 of account+date+description+amount
     * — caught by the unique index at import time) OR fuzzy (same account +
     * same amount ±$0.01 + same date ±3d + SAME whitespace-collapsed
     * description). The description equality is what stops two unrelated
     * $25 purchases three days apart from flagging each other as
     * duplicates; the external_id hash already encodes description, but
     * the fuzzy branch used to ignore it, producing false positives the
     * user then had to un-deselect row by row.
     */
    private function recomputeDuplicates(string $fileId): void
    {
        $state = $this->parsed[$fileId] ?? null;
        $accountId = $this->accountFor[$fileId] ?? null;
        if (! $state || ! $accountId || ($state['status'] ?? null) !== 'ready') {
            return;
        }
        $dupes = [];
        foreach ($state['rows'] as $i => $row) {
            $externalId = $this->externalIdFor($accountId, $row);
            $hasExternal = Transaction::where('account_id', $accountId)
                ->where('external_id', $externalId)
                ->exists();
            $rowDesc = trim((string) preg_replace('/\s+/', ' ', (string) ($row['description'] ?? '')));
            $hasFuzzy = ! $hasExternal
                ? Transaction::where('account_id', $accountId)
                    ->whereBetween('amount', [$row['amount'] - 0.005, $row['amount'] + 0.005])
                    ->whereBetween('occurred_on', [
                        CarbonImmutable::parse($row['occurred_on'])->subDays(3)->toDateString(),
                        CarbonImmutable::parse($row['occurred_on'])->addDays(3)->toDateString(),
                    ])
                    ->whereRaw(
                        "LOWER(TRIM(REGEXP_REPLACE(COALESCE(description, ''), '[[:space:]]+', ' '))) = ?",
                        [mb_strtolower($rowDesc)],
                    )
                    ->exists()
                : true;
            $dupes[$i] = $hasExternal || $hasFuzzy;
            if ($dupes[$i]) {
                $this->selected[$fileId][$i] = false;
            }
        }
        $this->duplicates[$fileId] = $dupes;
    }

    /**
     * @param  array{occurred_on: string, description: string, amount: float}  $row
     */
    private function externalIdFor(int $accountId, array $row): string
    {
        return sha1(implode('|', [
            $accountId,
            $row['occurred_on'],
            preg_replace('/\s+/', ' ', (string) $row['description']) ?? '',
            number_format((float) $row['amount'], 2, '.', ''),
        ]));
    }

    public function toggleRow(string $fileId, int $rowIndex): void
    {
        $this->selected[$fileId][$rowIndex] = ! ($this->selected[$fileId][$rowIndex] ?? false);
    }

    public function selectAllInFile(string $fileId, bool $on): void
    {
        $rows = $this->parsed[$fileId]['rows'] ?? [];
        $dupes = $this->duplicates[$fileId] ?? [];
        foreach ($rows as $i => $_) {
            // "Select all" respects the dedup pre-untick — don't force-select dupes.
            $this->selected[$fileId][$i] = $on && ! ($dupes[$i] ?? false);
        }
    }

    /**
     * The one row currently in edit mode, keyed as "<fileId>:<index>".
     * Empty = everything is read-only plain text. Rendering every
     * row's inputs + searchable-select simultaneously was unusable
     * past ~50 rows, so we show plain text by default and flip a
     * single row into edit mode via the Edit button.
     */
    public string $editingRow = '';

    /**
     * Per-edit snapshot of the row's pre-edit counterparty_id_override +
     * match_pattern. When we enter edit mode we pre-fill those fields
     * with the auto-detected vendor + its saved pattern so the user sees
     * the actual resolved state (instead of a misleading "Auto" label).
     * If the user cancels without changes, we restore from this snapshot
     * so the row reverts to pure auto-detect — otherwise "peek + cancel"
     * would silently lock in an explicit override.
     *
     * Keyed by "{fileId}:{rowIndex}". Discarded by saveRow on commit.
     *
     * @var array<string, array{counterparty_id_override: int|null, match_pattern: string}>
     */
    public array $preEditSnapshot = [];

    public function editRow(string $fileId, int $i): void
    {
        if (! isset($this->parsed[$fileId]['rows'][$i])) {
            return;
        }

        $row = $this->parsed[$fileId]['rows'][$i];
        $hasExistingEdit = ! empty($row['counterparty_id_override'])
            || trim((string) ($row['match_pattern'] ?? '')) !== '';

        // Only pre-fill when the row is pristine auto-detect. If the user
        // already edited this row (or its state was propagated from a
        // sibling row via saveRow), leave their values alone.
        if (! $hasExistingEdit) {
            $key = $fileId.':'.$i;
            $this->preEditSnapshot[$key] = [
                'counterparty_id_override' => $row['counterparty_id_override'] ?? null,
                'match_pattern' => (string) ($row['match_pattern'] ?? ''),
            ];

            [$resolvedId, $resolvedPattern] = $this->resolveRowForEdit($fileId, $i);
            if ($resolvedId !== null) {
                $this->parsed[$fileId]['rows'][$i]['counterparty_id_override'] = $resolvedId;
            }
            if ($resolvedPattern !== '') {
                $this->parsed[$fileId]['rows'][$i]['match_pattern'] = $resolvedPattern;
            }
        }

        $this->editingRow = $fileId.':'.$i;
    }

    public function cancelEdit(): void
    {
        // Undo any pre-edit pre-fill so cancelling returns the row to
        // pure auto-detect (no accidental override lock-in).
        if ($this->editingRow !== '' && isset($this->preEditSnapshot[$this->editingRow])) {
            [$fileId, $idx] = explode(':', $this->editingRow, 2);
            $i = (int) $idx;
            if (isset($this->parsed[$fileId]['rows'][$i])) {
                foreach ($this->preEditSnapshot[$this->editingRow] as $k => $v) {
                    $this->parsed[$fileId]['rows'][$i][$k] = $v;
                }
            }
            unset($this->preEditSnapshot[$this->editingRow]);
        }
        $this->editingRow = '';
    }

    /**
     * Resolve a row's auto-detected (contact_id, match_pattern) so edit
     * mode starts populated. Mirrors the import-time logic: pattern
     * match first (which carries the exact matched pattern string),
     * then fingerprint lookup against existing contacts. For the
     * fingerprint path we pull the contact's saved match_patterns (what
     * the import would reuse); for no match we fall back to the row's
     * own fingerprint as a suggestion for a new vendor.
     *
     * @return array{0: int|null, 1: string}
     */
    private function resolveRowForEdit(string $fileId, int $i): array
    {
        $row = $this->parsed[$fileId]['rows'][$i] ?? null;
        if (! is_array($row)) {
            return [null, ''];
        }

        $descLower = mb_strtolower((string) ($row['description'] ?? ''));

        // Pattern match wins — same as buildContactMap / vendorPreviewForFile.
        $hit = VendorReresolver::firstPatternHit($descLower, VendorReresolver::patternList());
        if ($hit !== null) {
            return [$hit[0], $hit[1]];
        }

        // Fingerprint lookup against existing contacts.
        $fp = $this->descriptionFingerprint((string) ($row['description'] ?? ''));
        if ($fp === '') {
            return [null, ''];
        }

        $contact = null;
        foreach (Contact::query()->get(['id', 'display_name', 'organization', 'match_patterns']) as $c) {
            foreach ([$c->display_name, $c->organization] as $candidate) {
                if (is_string($candidate) && $candidate !== ''
                    && $this->descriptionFingerprint($candidate) === $fp
                ) {
                    $contact = $c;
                    break 2;
                }
            }
        }

        if ($contact !== null) {
            $savedPattern = trim((string) ($contact->match_patterns ?? ''));

            return [(int) $contact->id, $savedPattern !== '' ? $savedPattern : $fp];
        }

        // No existing match — surface the fingerprint as the suggested
        // pattern for a new vendor the user may choose to create.
        return [null, $fp];
    }

    /**
     * Close the edit strip AND propagate the row's vendor + match
     * pattern to every OTHER row across every currently-loaded file
     * whose description matches the edited match_pattern (case-
     * insensitive regex). Rationale: the user's mental model for
     * editing a row is "fix this vendor and all similar ones" — when
     * they have a whole year's statements loaded side-by-side, a
     * same-file-only fan-out is surprising and tedious.
     */
    public function saveRow(string $fileId, int $i): void
    {
        if (isset($this->parsed[$fileId]['rows'][$i])) {
            $source = $this->parsed[$fileId]['rows'][$i];
            $pattern = trim((string) ($source['match_pattern'] ?? ''));
            $overrideId = $source['counterparty_id_override'] ?? null;

            if ($pattern !== '' && $overrideId !== null) {
                $needle = '#'.str_replace('#', '\#', $pattern).'#iu';
                foreach ($this->parsed as $otherFileId => $otherFile) {
                    if (! isset($otherFile['rows']) || ! is_array($otherFile['rows'])) {
                        continue;
                    }
                    foreach ($otherFile['rows'] as $idx => $otherRow) {
                        // Skip the row the user just edited; its
                        // vendor/pattern are already set.
                        if ($otherFileId === $fileId && (string) $idx === (string) $i) {
                            continue;
                        }
                        $descLower = mb_strtolower((string) ($otherRow['description'] ?? ''));
                        if (@preg_match($needle, $descLower) !== 1) {
                            continue;
                        }
                        $this->parsed[$otherFileId]['rows'][$idx]['counterparty_id_override'] = $overrideId;
                        $this->parsed[$otherFileId]['rows'][$idx]['match_pattern'] = $pattern;
                    }
                }
            }
        }
        // Commit: the pre-fill is now the user's explicit choice, so
        // drop the snapshot (no revert-on-cancel after this point).
        unset($this->preEditSnapshot[$fileId.':'.$i]);
        $this->editingRow = '';
    }

    /**
     * Inline vendor-creation from the searchable-select on a preview
     * row. Writes the new contact id into the row's
     * counterparty_id_override and seeds match_patterns from the
     * row's match_pattern field so the pattern the user just typed
     * (or the auto-filled fingerprint) becomes the new vendor's rule.
     * Dispatches ss-option-added so the dropdown refreshes its label
     * without a full re-render.
     */
    public function createCounterpartyForRow(string $name, string $modelKey): void
    {
        $name = trim($name);
        if ($name === '' || ! str_starts_with($modelKey, 'parsed.')) {
            return;
        }
        // Expect shape: parsed.<fileId>.rows.<index>.counterparty_id_override
        $parts = explode('.', $modelKey);
        if (count($parts) !== 5 || $parts[0] !== 'parsed' || $parts[2] !== 'rows' || $parts[4] !== 'counterparty_id_override') {
            return;
        }
        [$fileId, $rowIndex] = [$parts[1], $parts[3]];
        if (! isset($this->parsed[$fileId]['rows'][$rowIndex])) {
            return;
        }

        $pattern = trim((string) ($this->parsed[$fileId]['rows'][$rowIndex]['match_pattern'] ?? ''));
        if ($pattern === '') {
            // Empty match_pattern means the user didn't type one — seed the
            // new contact from the row's fingerprint so future imports link
            // back to this vendor instead of creating a duplicate.
            $desc = (string) ($this->parsed[$fileId]['rows'][$rowIndex]['description'] ?? '');
            $pattern = $this->descriptionFingerprint($desc);
        }
        $contact = Contact::create([
            'kind' => 'org',
            'display_name' => $name,
            'is_vendor' => true,
            'match_patterns' => $pattern !== '' ? $pattern : null,
        ]);

        $this->parsed[$fileId]['rows'][$rowIndex]['counterparty_id_override'] = (int) $contact->id;

        $this->dispatch('ss-option-added',
            model: $modelKey,
            id: (int) $contact->id,
            label: $contact->display_name,
        );
    }

    /** @return Collection<int, Contact> */
    #[Computed]
    public function contactOptions(): Collection
    {
        return Contact::query()->orderBy('display_name')->get(['id', 'display_name']);
    }

    public function dismissFile(string $fileId): void
    {
        if (isset($this->parsed[$fileId]['disk_path']) && ($this->parsed[$fileId]['media_id'] ?? null) === null) {
            try {
                Storage::disk('local')->delete($this->parsed[$fileId]['disk_path']);
            } catch (Throwable) {
            }
        }
        unset($this->parsed[$fileId], $this->accountFor[$fileId], $this->selected[$fileId], $this->duplicates[$fileId]);
    }

    public function clearAll(): void
    {
        foreach (array_keys($this->parsed) as $fileId) {
            $this->dismissFile($fileId);
        }
        $this->bulkMessage = null;
    }

    public function importFile(string $fileId): int
    {
        return $this->persistFile($fileId);
    }

    public function importAll(): void
    {
        $totalCreated = 0;
        $totalSkippedDup = 0;
        $totalSkippedInvalid = 0;
        $needAccount = 0;
        foreach (array_keys($this->parsed) as $fileId) {
            if (($this->parsed[$fileId]['status'] ?? null) !== 'ready') {
                continue;
            }
            if (! ($this->accountFor[$fileId] ?? null)) {
                $needAccount++;

                continue;
            }
            $created = $this->persistFile($fileId);
            $totalCreated += $created;
            $reasons = $this->parsed[$fileId]['skip_reasons'] ?? [];
            $totalSkippedDup += ($reasons['duplicate'] ?? 0);
            $totalSkippedInvalid += ($reasons['invalid'] ?? 0);
        }

        // Collapse cross-account debit/credit pairs into Transfer records.
        // Keeps net worth honest (same money shouldn't appear on both sides).
        $pairedTransfers = 0;
        $household = CurrentHousehold::get();
        if ($household && $totalCreated > 0) {
            $pairedTransfers = app(TransferPairing::class)->pair($household);
        }

        $parts = [__(':c transactions created.', ['c' => $totalCreated])];
        if ($totalSkippedDup > 0) {
            $parts[] = __(':n skipped as duplicates (same date+amount+account already on file).', ['n' => $totalSkippedDup]);
        }
        if ($totalSkippedInvalid > 0) {
            $parts[] = __(':n skipped with missing date or amount.', ['n' => $totalSkippedInvalid]);
        }
        if ($pairedTransfers > 0) {
            $parts[] = __(':p transfer pair(s) linked.', ['p' => $pairedTransfers]);
        }
        if ($needAccount > 0) {
            // Surface silent skips — files without an account selection were
            // previously dropped on the floor. Now the user sees the gap and
            // can pick an account + re-click Import.
            $parts[] = __(':n file(s) still need an account — pick one and re-run Import.', ['n' => $needAccount]);
        }
        $this->bulkMessage = implode(' ', $parts);
    }

    private function persistFile(string $fileId): int
    {
        $state = $this->parsed[$fileId] ?? null;
        $accountId = $this->accountFor[$fileId] ?? null;
        if (! $state || ($state['status'] ?? null) !== 'ready' || ! $accountId) {
            return 0;
        }

        $media = $this->persistMedia($fileId);
        $created = 0;
        // Skip reasons tracked per-file so the review card can explain
        // exactly why a row didn't land instead of an opaque count.
        $skipReasons = [
            'duplicate' => 0,  // (account_id, external_id) unique-index collision
            'invalid' => 0,    // row missing / malformed date or amount
        ];

        // Pre-scan: build a fingerprint → Contact id map. Existing contacts
        // get matched, and descriptions that repeat ≥2 times in this batch
        // become new Contacts (kind=org) so future imports auto-link.
        $contactMap = $this->buildContactMap($state['rows']);

        // Also load explicit Contact match_patterns — these bypass the
        // fingerprint logic so renamed contacts stay linked to their
        // descriptions.
        $patternList = VendorReresolver::patternList();

        // Category source-label patterns — used to map the statement's
        // own Category column (e.g. Costco's "Merchandise") onto a
        // household category. Loaded once so the per-row resolve is
        // O(patterns) instead of O(patterns × rows).
        $categoryPatternList = CategorySourceMatcher::patternList();

        // Pending pattern appends for existing vendors the user picked
        // explicitly (counterparty_id_override). Collected inside the row
        // loop, persisted in one pass afterwards so each contact gets a
        // single UPDATE regardless of how many rows reference it.
        //
        // @var array<int, array<int, string>>
        $pendingPatternAppends = [];

        DB::transaction(function () use ($fileId, $state, $accountId, $media, $contactMap, $patternList, $categoryPatternList, &$created, &$skipReasons, &$pendingPatternAppends) {
            foreach ($state['rows'] as $i => $row) {
                if (! ($this->selected[$fileId][$i] ?? false)) {
                    continue;
                }
                $rawDate = $row['occurred_on'] ?? null;
                $rawAmount = $row['amount'] ?? null;
                if (! is_string($rawDate) || $rawDate === '' || ! is_numeric($rawAmount)) {
                    $skipReasons['invalid']++;

                    continue;
                }
                $externalId = $this->externalIdFor($accountId, $row);
                // Counterparty resolution priority:
                //   1. Per-row override (user picked / added in the
                //      preview dropdown) — wins absolutely.
                //   2. Pattern match against any Contact's declared
                //      match_patterns.
                //   3. Fingerprint map built by buildContactMap.
                $counterpartyId = null;
                if (! empty($row['counterparty_id_override'])) {
                    $counterpartyId = (int) $row['counterparty_id_override'];

                    // Queue the row's match_pattern for append onto the
                    // picked vendor. Deduplication + display-name-fp skip
                    // happen after the loop where we have the contact's
                    // full state loaded.
                    $explicitPattern = trim((string) ($row['match_pattern'] ?? ''));
                    if ($explicitPattern !== '') {
                        $pendingPatternAppends[$counterpartyId][] = $explicitPattern;
                    }
                } else {
                    $descLower = mb_strtolower((string) $row['description']);
                    $counterpartyId = VendorReresolver::firstPatternMatch($descLower, $patternList);
                    if ($counterpartyId === null) {
                        $fingerprint = $this->descriptionFingerprint((string) $row['description']);
                        $counterpartyId = $contactMap[$fingerprint] ?? null;
                    }
                }
                try {
                    $txn = Transaction::create([
                        'account_id' => $accountId,
                        'occurred_on' => $rawDate,
                        'amount' => $rawAmount,
                        'currency' => Account::find($accountId)?->currency ?? 'USD',
                        'closing_balance' => $row['closing_balance'] ?? null,
                        'description' => $row['description'],
                        'check_number' => $row['check_number'] ?? null,
                        'counterparty_contact_id' => $counterpartyId,
                        'status' => 'cleared',
                        'external_id' => $externalId,
                        'import_source' => (string) ($state['import_source'] ?? 'statement:unknown'),
                    ]);
                } catch (QueryException $e) {
                    // Unique-constraint collision (account_id, external_id) —
                    // row was imported by a concurrent request or a prior
                    // import we missed in the dedup scan.
                    $skipReasons['duplicate']++;

                    continue;
                }
                if ($media && ! $txn->media()->where('media.id', $media->id)->exists()) {
                    $txn->media()->attach($media->id, ['role' => 'statement']);
                }
                // Fall back to the statement's source-category label when
                // nothing upstream (contact default, description rule)
                // claimed the transaction. User-curated rules win; this
                // is strictly fill-in.
                $hint = $row['category_hint'] ?? null;
                if ($hint !== null && $hint !== '' && $txn->fresh()?->category_id === null) {
                    $catId = CategorySourceMatcher::match((string) $hint, $categoryPatternList);
                    if ($catId !== null) {
                        $txn->forceFill(['category_id' => $catId])->save();
                    }
                }
                ProjectionMatcher::attempt($txn);
                $created++;
            }

            // Persist the pattern edits the user made on rows with an
            // explicit vendor override. Runs once per touched contact
            // (not per row) and dedupes case-insensitively against the
            // existing match_patterns list. The display-name fingerprint
            // is skipped because patternList() already self-heals it in.
            foreach ($pendingPatternAppends as $contactId => $patterns) {
                $contact = Contact::find($contactId);
                if ($contact === null) {
                    continue;
                }
                $existing = VendorReresolver::parsePatterns((string) ($contact->match_patterns ?? ''));
                $existingLower = array_map(fn ($p) => mb_strtolower($p), $existing);
                $displayFp = $this->descriptionFingerprint((string) $contact->display_name);

                $additions = [];
                foreach (array_values(array_unique($patterns)) as $pattern) {
                    $pl = mb_strtolower($pattern);
                    if (in_array($pl, $existingLower, true)) {
                        continue;
                    }
                    if ($pl === $displayFp) {
                        continue;
                    }
                    $additions[] = $pattern;
                    $existingLower[] = $pl;
                }

                if ($additions !== []) {
                    $combined = implode("\n", array_merge($existing, $additions));
                    $contact->forceFill(['match_patterns' => $combined])->save();
                }
            }
        });

        $skipped = array_sum($skipReasons);
        $this->parsed[$fileId]['created_count'] = ($this->parsed[$fileId]['created_count'] ?? 0) + $created;
        $this->parsed[$fileId]['skipped_count'] = ($this->parsed[$fileId]['skipped_count'] ?? 0) + $skipped;
        $this->parsed[$fileId]['skip_reasons'] = array_merge(
            $this->parsed[$fileId]['skip_reasons'] ?? ['duplicate' => 0, 'invalid' => 0],
            [
                'duplicate' => ($this->parsed[$fileId]['skip_reasons']['duplicate'] ?? 0) + $skipReasons['duplicate'],
                'invalid' => ($this->parsed[$fileId]['skip_reasons']['invalid'] ?? 0) + $skipReasons['invalid'],
            ],
        );
        if ($media) {
            $this->parsed[$fileId]['media_id'] = $media->id;
        }

        return $created;
    }

    /**
     * Map description-fingerprints to Contact ids for this file's rows.
     * Existing Contacts matched by normalized display_name/organization;
     * repeated-but-unknown merchants (≥2 occurrences in the batch) get a
     * new Contact so future imports auto-link. One-off charges are left
     * unlinked — no contact spam from random receipts.
     *
     * @param  array<int, array{description: string, amount: float, occurred_on: string}>  $rows
     * @return array<string, int>
     */
    /**
     * Preview of how every row in a parsed file will be mapped to a
     * counterparty Contact on import — shown in the per-file table so
     * the user can spot misclassifications BEFORE hitting Import.
     *
     * Returns [rowIndex => ['status' => existing|new|skip, 'label' => string]]:
     *   - existing: fingerprint matches a Contact already in the
     *     database (label is the contact's display_name).
     *   - new: would auto-create a Contact on import (fingerprint
     *     repeats ≥ 2 times in this file; label is the humanized name
     *     we'd seed it with).
     *   - skip: no existing match and no repeat — the row lands with
     *     counterparty_contact_id = null, no Contact created.
     *
     * Matches buildContactMap()'s logic exactly so the preview can't
     * drift from the import.
     *
     * @return array<int, array{status: string, label: string, matched_pattern?: string}>
     */
    public function vendorPreviewForFile(string $fileId): array
    {
        $rows = (array) ($this->parsed[$fileId]['rows'] ?? []);
        if ($rows === []) {
            return [];
        }

        $counts = [];
        $fingerprints = [];
        foreach ($rows as $idx => $row) {
            $fp = $this->descriptionFingerprint((string) ($row['description'] ?? ''));
            $fingerprints[$idx] = $fp;
            if ($fp !== '') {
                $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            }
        }

        $existing = $this->contactFingerprints();
        $patternList = VendorReresolver::patternList();
        $patternNamesById = Contact::query()
            ->whereIn('id', array_unique(array_column($patternList, 0)))
            ->pluck('display_name', 'id');

        $out = [];
        foreach ($rows as $idx => $row) {
            // Pattern match wins — shows the contact name it'll link to
            // and surfaces *which* pattern fired so the user can spot-check.
            $descLower = mb_strtolower((string) ($row['description'] ?? ''));
            $patternHit = VendorReresolver::firstPatternHit($descLower, $patternList);
            if ($patternHit !== null) {
                [$hitId, $hitPattern] = $patternHit;
                $out[$idx] = [
                    'status' => 'existing',
                    'label' => (string) ($patternNamesById[$hitId] ?? ''),
                    'matched_pattern' => $hitPattern,
                ];

                continue;
            }

            $fp = $fingerprints[$idx] ?? '';
            if ($fp === '') {
                $out[$idx] = ['status' => 'skip', 'label' => '—'];

                continue;
            }
            if (isset($existing[$fp])) {
                $out[$idx] = ['status' => 'existing', 'label' => (string) $existing[$fp]];

                continue;
            }
            if (($counts[$fp] ?? 0) >= 2) {
                $out[$idx] = [
                    'status' => 'new',
                    'label' => $this->humanizeDescription((string) ($row['description'] ?? '')),
                ];

                continue;
            }
            $out[$idx] = ['status' => 'skip', 'label' => '—'];
        }

        return $out;
    }

    /**
     * Distinct source-category labels emitted by this file's parser,
     * each flagged as mapped (patterns on some category matched) or
     * unmapped (user needs to decide). Feeds the "Category hints"
     * panel on the preview so the user can seed `categories.match_patterns`
     * with one click before import.
     *
     * @return array<int, array{label: string, count: int, status: string, category_id?: int, category_name?: string}>
     */
    public function categoryHintsForFile(string $fileId): array
    {
        $rows = (array) ($this->parsed[$fileId]['rows'] ?? []);
        if ($rows === []) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['category_hint'] ?? ''));
            if ($label === '') {
                continue;
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        if ($counts === []) {
            return [];
        }

        $patternList = CategorySourceMatcher::patternList();
        $categoryNames = Category::query()
            ->whereIn('id', array_unique(array_column($patternList, 0)))
            ->pluck('name', 'id');

        $out = [];
        foreach ($counts as $label => $n) {
            $hit = CategorySourceMatcher::matchWithPattern($label, $patternList);
            if ($hit !== null) {
                [$catId] = $hit;
                $out[] = [
                    'label' => $label,
                    'count' => $n,
                    'status' => 'mapped',
                    'category_id' => $catId,
                    'category_name' => (string) ($categoryNames[$catId] ?? ''),
                ];
            } else {
                $out[] = [
                    'label' => $label,
                    'count' => $n,
                    'status' => 'unmapped',
                ];
            }
        }

        // Most-frequent first so the biggest wins sit at the top of the panel.
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Append the source label to an existing category's match_patterns
     * so this and future imports auto-map. Deduped case-insensitively.
     */
    public function mapCategoryHint(string $fileId, string $label, int $categoryId): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        $category = Category::find($categoryId);
        if (! $category) {
            return;
        }
        $existing = CategorySourceMatcher::parsePatterns((string) ($category->match_patterns ?? ''));
        $lower = array_map('mb_strtolower', $existing);
        if (! in_array(mb_strtolower($label), $lower, true)) {
            $existing[] = $label;
            $category->forceFill(['match_patterns' => implode("\n", $existing)])->save();
        }
    }

    /**
     * Resolve the Category each row will land on by mirroring the
     * import cascade without touching the DB:
     *
     *   1. counterparty.category_id (the row's Contact's default)
     *   2. first CategoryRule whose pattern matches the description
     *   3. first Category whose match_patterns hit the source label
     *
     * First hit wins — same order as TransactionObserver + our own
     * fallback in the importer. Returns per-row `[category_id,
     * category_name, source]`, with `source` in `contact | rule |
     * hint` so the UI can render a provenance icon.
     *
     * Built once per file and memo'd on the call path so the per-row
     * render doesn't re-read CategoryRule / match_patterns / Contact
     * for each row.
     *
     * @return array<int, array{category_id: int, category_name: string, source: string}>
     */
    public function categoryResolutionForFile(string $fileId): array
    {
        $rows = (array) ($this->parsed[$fileId]['rows'] ?? []);
        if ($rows === []) {
            return [];
        }

        $vendorPreview = $this->vendorPreviewForFile($fileId);
        $vendorPatternList = VendorReresolver::patternList();
        $contactCategories = Contact::query()
            ->whereNotNull('category_id')
            ->pluck('category_id', 'id');

        $hintPatternList = CategorySourceMatcher::patternList();

        $rules = CategoryRule::query()
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'pattern_type', 'pattern', 'category_id']);

        $nameLookup = Category::query()->pluck('name', 'id');

        $out = [];
        foreach ($rows as $i => $row) {
            $catId = null;
            $source = null;

            // 1 — contact default. Use the preview logic to figure out
            // which contact this row will land on. For rows with an
            // explicit counterparty_id_override we trust the override.
            $contactId = $row['counterparty_id_override'] ?? null;
            if (! $contactId) {
                $descLower = mb_strtolower((string) ($row['description'] ?? ''));
                $hit = VendorReresolver::firstPatternMatch($descLower, $vendorPatternList);
                if ($hit !== null) {
                    $contactId = $hit;
                }
            }
            if ($contactId && isset($contactCategories[(int) $contactId])) {
                $catId = (int) $contactCategories[(int) $contactId];
                $source = 'contact';
            }

            // 2 — description rule.
            if ($catId === null) {
                $desc = (string) ($row['description'] ?? '');
                foreach ($rules as $rule) {
                    if (self::categoryRuleMatches((string) $rule->pattern_type, (string) $rule->pattern, $desc)) {
                        $catId = (int) $rule->category_id;
                        $source = 'rule';
                        break;
                    }
                }
            }

            // 3 — hint fallback.
            if ($catId === null) {
                $label = trim((string) ($row['category_hint'] ?? ''));
                if ($label !== '') {
                    $hinted = CategorySourceMatcher::match($label, $hintPatternList);
                    if ($hinted !== null) {
                        $catId = $hinted;
                        $source = 'hint';
                    }
                }
            }

            if ($catId !== null) {
                $out[$i] = [
                    'category_id' => $catId,
                    'category_name' => (string) ($nameLookup[$catId] ?? ''),
                    'source' => (string) $source,
                ];
            }
        }

        return $out;
    }

    /** Local copy of CategoryRuleMatcher::matches — keeps the preview
     *  path decoupled from the observer's internals. */
    private static function categoryRuleMatches(string $patternType, string $pattern, string $haystack): bool
    {
        if ($pattern === '' || $haystack === '') {
            return false;
        }
        if ($patternType === 'regex') {
            $delimited = '/'.str_replace('/', '\\/', $pattern).'/i';

            return @preg_match($delimited, $haystack) === 1;
        }

        return mb_stripos($haystack, $pattern) !== false;
    }

    /**
     * Reverse of mapCategoryHint — strip the pattern that's currently
     * claiming this source label so it goes back to being unmapped.
     * Walks the matcher to find which (category, pattern) pair fires
     * on this label, then rewrites that category's match_patterns
     * without the hit. The hint flips back to "unmapped" on the next
     * render and the user can pick again.
     */
    public function unmapCategoryHint(string $fileId, string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        $hit = CategorySourceMatcher::matchWithPattern($label);
        if ($hit === null) {
            return;
        }
        [$categoryId, $pattern] = $hit;

        $category = Category::find($categoryId);
        if (! $category) {
            return;
        }

        $kept = array_values(array_filter(
            CategorySourceMatcher::parsePatterns((string) ($category->match_patterns ?? '')),
            fn (string $p) => $p !== $pattern,
        ));
        $category->forceFill(['match_patterns' => $kept === [] ? null : implode("\n", $kept)])->save();
    }

    /**
     * Create a new household expense category that future imports will
     * auto-map this label to. `$name` is what the user actually wants
     * to call the category (they may want "Shopping" even though the
     * statement says "Merchandise"); when omitted or empty, the raw
     * source label is used. The source label is always seeded into
     * match_patterns so the mapping sticks regardless of the chosen
     * display name.
     */
    public function createCategoryFromHint(string $fileId, string $label, ?string $name = null): void
    {
        $label = trim($label);
        if ($label === '') {
            return;
        }
        $displayName = trim((string) $name);
        if ($displayName === '') {
            $displayName = $label;
        }

        $slug = Str::slug($displayName) ?: 'cat-'.bin2hex(random_bytes(3));
        $base = $slug;
        $suffix = 0;
        while (Category::where('slug', $suffix ? "{$base}-{$suffix}" : $base)->exists()) {
            $suffix++;
        }
        Category::create([
            'name' => $displayName,
            'slug' => $suffix ? "{$base}-{$suffix}" : $base,
            'kind' => 'expense',
            'match_patterns' => $label,
        ]);
    }

    /**
     * Memoised fingerprint → display_name lookup for every existing
     * Contact, matching buildContactMap()'s fingerprinting exactly.
     * Computed once per request so the preview re-renders don't hit
     * the DB per file.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function contactFingerprints(): array
    {
        $map = [];
        $contacts = Contact::query()->get(['id', 'display_name', 'organization']);
        foreach ($contacts as $c) {
            foreach ([$c->display_name, $c->organization] as $candidate) {
                if (! is_string($candidate) || $candidate === '') {
                    continue;
                }
                $fp = $this->descriptionFingerprint($candidate);
                if ($fp !== '' && ! isset($map[$fp])) {
                    $map[$fp] = (string) $c->display_name;
                }
            }
        }

        return $map;
    }

    private function buildContactMap(array $rows): array
    {
        $counts = [];
        $originals = [];
        // Per-fingerprint user-edited match_pattern — used when
        // auto-creating the Contact so the user's edit (if any)
        // becomes the new vendor's pattern instead of the bare
        // fingerprint.
        $patternOverrides = [];
        foreach ($rows as $row) {
            // Skip rows whose counterparty the user already pinned —
            // buildContactMap shouldn't create extra contacts for them.
            if (! empty($row['counterparty_id_override'])) {
                continue;
            }
            $fp = $this->descriptionFingerprint((string) $row['description']);
            if ($fp === '') {
                continue;
            }
            $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            if (! isset($originals[$fp])) {
                $originals[$fp] = (string) $row['description'];
            }
            // First non-empty match_pattern wins for this fingerprint —
            // subsequent rows in the same fingerprint group can still
            // override their own counterparty via the dropdown if they
            // disagree.
            if (! isset($patternOverrides[$fp])) {
                $override = trim((string) ($row['match_pattern'] ?? ''));
                if ($override !== '') {
                    $patternOverrides[$fp] = $override;
                }
            }
        }

        // Fingerprint existing contacts once, in PHP. The old LIKE query
        // required the fingerprint to appear as a contiguous substring of
        // the contact's display_name — which it often doesn't, because
        // the fingerprint skips short words (e.g. "home auto") while
        // humanizeDescription keeps every word ≥3 chars (e.g. "Home Mtg
        // Auto"). That mismatch made every re-import miss the existing
        // vendor and create a fresh duplicate.
        $vendorFingerprints = [];
        $contacts = Contact::query()->get(['id', 'display_name', 'organization']);
        foreach ($contacts as $c) {
            foreach ([$c->display_name, $c->organization] as $candidate) {
                if (! is_string($candidate) || $candidate === '') {
                    continue;
                }
                $cfp = $this->descriptionFingerprint($candidate);
                if ($cfp !== '' && ! isset($vendorFingerprints[$cfp])) {
                    $vendorFingerprints[$cfp] = (int) $c->id;
                }
            }
        }

        // Explicit match_patterns win over fingerprints. For each
        // unique fingerprint, test its representative description
        // against every Contact's patterns — on a hit, reuse that
        // contact instead of auto-creating. Handles the case where
        // "Pixlr Pte Ltd 07/15 Singapore" fingerprints to "pixlr
        // singapore" while the existing Pixlr contact has display-
        // name fingerprint "pixlr" — match_patterns bridges the gap.
        $patternList = VendorReresolver::patternList();

        $map = [];
        foreach ($counts as $fp => $count) {
            if (isset($vendorFingerprints[$fp])) {
                $map[$fp] = $vendorFingerprints[$fp];

                continue;
            }

            // Pattern match against the representative description —
            // skips duplicate-creation when any existing contact's
            // patterns match.
            $descLower = mb_strtolower((string) ($originals[$fp] ?? ''));
            $patternHit = VendorReresolver::firstPatternMatch($descLower, $patternList);
            if ($patternHit !== null) {
                $map[$fp] = $patternHit;
                $vendorFingerprints[$fp] = $patternHit;

                continue;
            }

            // Auto-create only for repeating merchants — keeps contacts clean.
            if ($count >= 2) {
                $contact = Contact::create([
                    'kind' => 'org',
                    'display_name' => $this->humanizeDescription($originals[$fp] ?? $fp),
                    'is_vendor' => true,
                    // Prefer the user's edited match_pattern (from the
                    // preview row) over the bare fingerprint so any
                    // tightening/broadening they did before hitting
                    // Import lands on the new vendor.
                    'match_patterns' => $patternOverrides[$fp] ?? $fp,
                ]);
                $map[$fp] = $contact->id;
                // Seed the lookup so subsequent fingerprints in this same
                // batch see the brand-new contact instead of creating
                // another copy of it.
                $vendorFingerprints[$fp] = $contact->id;
            }
        }

        return $map;
    }

    private function descriptionFingerprint(string $raw): string
    {
        // Strip household-configured filler ("Purchase authorized on",
        // "POS purchase", etc.) before anything else — otherwise the
        // first two meaningful words come from boilerplate shared
        // across every transaction, collapsing distinct vendors into
        // one fingerprint bucket.
        $raw = DescriptionNormalizer::stripIgnoredPatterns($raw);
        $lower = mb_strtolower($raw);
        // Cut at the first digit / # / * the same way humanizeDescription
        // does so the fingerprint is stable across transaction-specific
        // suffixes (check numbers, ATM addresses, timestamps, reference
        // ids). Without this, "Non-WF ATM Withdrawal 4326 Main St" and
        // "Non-WF ATM Withdrawal 5678 Oak Ave" picked up different fps
        // ("withdrawal main" vs "withdrawal") while their humanized
        // display_names collapsed to the same string — a perfect recipe
        // for duplicate Contacts on every re-import.
        $lower = (string) preg_replace('/[\d#*].*$/', '', $lower);
        $lower = (string) preg_replace('/[^a-z\s]+/', ' ', $lower);
        $words = preg_split('/\s+/', trim($lower)) ?: [];
        $meaningful = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4));

        return implode(' ', array_slice($meaningful, 0, 2));
    }

    /**
     * Turn a raw transaction description into a human-friendly contact name.
     * "NETFLIX.COM 12345" → "Netflix". Conservative: takes the first
     * meaningful token, title-cases it.
     */
    private function humanizeDescription(string $raw): string
    {
        // Same filler strip as the fingerprint — otherwise the
        // auto-created Contact display_name would be titled from
        // "Purchase authorized on" rather than the real vendor.
        $raw = DescriptionNormalizer::stripIgnoredPatterns($raw);
        $cleaned = (string) preg_replace('/[\d#*].*$/i', '', $raw);
        $words = preg_split('/\s+/', trim($cleaned)) ?: [];
        $meaningful = array_filter($words, fn ($w) => mb_strlen($w) >= 3);
        $joined = implode(' ', array_slice($meaningful, 0, 3));
        $joined = ucwords(mb_strtolower($joined));

        return $joined !== '' ? $joined : Str::limit($raw, 120);
    }

    private function persistMedia(string $fileId): ?Media
    {
        $state = $this->parsed[$fileId];
        if (empty($state['hash']) || empty($state['disk_path'])) {
            return null;
        }
        if (! empty($state['media_id'])) {
            return Media::find($state['media_id']);
        }
        $household = CurrentHousehold::get();

        return Media::create([
            'household_id' => $household?->id,
            'disk' => 'local',
            'source' => 'upload',
            'path' => $state['disk_path'],
            'original_name' => $state['name'] ?? 'statement',
            'mime' => $state['mime'] ?? null,
            'size' => $state['size'] ?? null,
            'hash' => $state['hash'],
            'captured_at' => now(),
            'ocr_status' => 'skip',  // statement bodies aren't OCRed — parsed already
            'folder_id' => MediaFolders::idFor(MediaFolders::STATEMENTS),
        ]);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::orderBy('name')->get(['id', 'name', 'currency', 'type']);
    }

    /**
     * Categories list for the "map to existing" dropdown in the
     * category-hints panel. Kept computed so the per-hint render doesn't
     * re-query for each row.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::with('parent:id,name')
            ->orderBy('kind')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'slug', 'parent_id']);
    }
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Import statements') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Drop PDF or CSV exports from Wells Fargo, Citi, Amex, or PayPal. Each file is parsed, deduped, and queued for review before anything hits your ledger.') }}
        </p>
    </header>

    {{-- Inline vendor-ignore editor so filler patterns can be adjusted
         mid-import without bouncing to /settings. Collapsed by default;
         expand when a preview shows too much filler polluting vendor
         matches. The "Re-resolve now" button inside also retroactively
         re-assigns existing imports if the user fixes a rule. --}}
    <details class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-4 text-xs">
        <summary class="cursor-pointer text-neutral-300">
            {{ __('Vendor auto-detect · ignore list') }}
            <span class="ml-1 text-neutral-600">{{ __('(edit without leaving this page)') }}</span>
        </summary>
        <div class="mt-3">
            <livewire:vendor-ignore-editor />
        </div>
    </details>

    {{-- Default account picker — applied to every subsequent file AND to any
         already-uploaded card that hasn't had its account set yet. Saves the
         "pick the account once per card" tedium when a batch is all from the
         same source. --}}
    <div class="flex flex-wrap items-center gap-3 rounded-md border border-neutral-800 bg-neutral-900/40 px-3 py-2">
        <label for="statements-default-account" class="text-[11px] uppercase tracking-wider text-neutral-500">
            {{ __('Default account for uploads') }}
        </label>
        <select id="statements-default-account" wire:model.live="defaultAccountId"
                class="rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">{{ __('— pick per file') }}</option>
            @foreach ($this->accounts as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
            @endforeach
        </select>
        @if ($defaultAccountId)
            <span class="text-[11px] text-neutral-500">{{ __('Pre-applied to each uploaded file; still editable per card.') }}</span>
        @endif

        <span class="mx-2 h-5 w-px bg-neutral-800" aria-hidden="true"></span>

        {{-- Global year override. When a statement's period header is
             missing, the parser falls back to the current calendar year
             and a 2024 January statement gets imported as 2026. This
             input lets the user pin the right year for the whole batch
             (per-file input below overrides for a specific card). --}}
        <label for="statements-global-year" class="text-[11px] uppercase tracking-wider text-neutral-500">
            {{ __('Year (all)') }}
        </label>
        <input id="statements-global-year" type="number"
               min="2000" max="2099" step="1" placeholder="{{ __('auto') }}"
               wire:model.live.debounce.400ms="globalYear"
               class="w-20 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <span class="text-[11px] text-neutral-500">{{ __('Leave blank to use the year detected from each file.') }}</span>
    </div>

    {{-- Drag-and-drop zone. The <label> alone handles click-to-upload but the
         browser default is to NAVIGATE to the dropped file rather than feed it
         to the input — so we intercept drop events and copy the files through
         a DataTransfer, then fire a native `change` so Livewire's wire:model
         picks them up. Dashed border is bumped to 2px and re-colored on
         dragover so the drop target is unambiguous. --}}
    <div x-data="{
             over: false,
             uploadError: '',
         }"
         x-on:dragover.prevent="over = true"
         x-on:dragleave.prevent="over = false"
         x-on:drop.prevent="
            over = false;
            if (!$event.dataTransfer?.files?.length) return;
            const dt = new DataTransfer();
            for (const f of $event.dataTransfer.files) dt.items.add(f);
            $refs.fileInput.files = dt.files;
            $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
         "
         {{-- Livewire emits upload-error on the wire:model element when the
              temp-upload endpoint returns 4xx/5xx (post_max_size, mime reject,
              nginx 413). Catch it so the user sees a clear message instead of
              a hung progress indicator. --}}
         x-on:livewire-upload-error.window="
            uploadError = ($event.detail?.message
                ?? @js(__('Upload failed. A file may be too large, blocked by your server config, or your connection dropped.')));
         "
         x-on:livewire-upload-finish.window="uploadError = ''"
         :class="over
            ? 'border-emerald-500 bg-emerald-950/20'
            : 'border-neutral-700 bg-neutral-900/40'"
         class="relative rounded-xl border-2 border-dashed p-5 transition-colors">
        <label class="flex cursor-pointer flex-col items-center gap-2 text-sm text-neutral-300">
            <input x-ref="fileInput" type="file" wire:model="files" multiple accept=".pdf,.csv,.tsv,.zip" class="sr-only" aria-label="{{ __('Upload statements') }}">
            <svg class="h-8 w-8 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 4v12m-4-4 4 4 4-4"/><path d="M4 20h16"/>
            </svg>
            <span x-text="over ? @js(__('Drop to upload')) : @js(__('Click to choose files, or drop them here'))">{{ __('Click to choose files, or drop them here') }}</span>
            <span class="text-[11px] text-neutral-500">{{ __('PDF, CSV, and ZIP archives accepted · multiple files OK') }}</span>
        </label>

        {{-- Full-cover overlay while Livewire is streaming + parsing the
             files. The upload phase is short; the heavy wait is PDF/CSV
             parsing inside updatedFiles(), so the label covers both. --}}
        {{-- wire:loading.flex (not bare wire:loading) so Livewire's auto-toggled
             display stays `flex` — otherwise it reverts to `inline-block` and
             the flex centering collapses, leaving the spinner top-left. --}}
        <div wire:loading.flex wire:target="files"
             role="status" aria-live="polite"
             class="absolute inset-0 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-emerald-500 bg-neutral-950/85 text-sm font-medium text-emerald-200">
            <svg class="h-6 w-6 animate-spin text-emerald-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span>{{ __('Uploading & parsing files…') }}</span>
            <span class="text-[11px] font-normal text-emerald-300/70">{{ __('PDFs can take a few seconds per file.') }}</span>
        </div>

        {{-- Livewire-layer upload failure (post_max_size / 413 / mime reject /
             network drop). Dismissable; re-shows on any subsequent failure. --}}
        <div x-show="uploadError" x-cloak x-transition.opacity
             role="alert"
             class="mt-3 flex items-start gap-2 rounded-md border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-xs text-rose-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-rose-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/>
            </svg>
            <span x-text="uploadError" class="flex-1"></span>
            <button type="button" x-on:click="uploadError = ''"
                    class="shrink-0 rounded px-1 text-rose-300 hover:text-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                    aria-label="{{ __('Dismiss') }}">×</button>
        </div>
    </div>

    @if ($bulkMessage)
        <div role="status" class="rounded-md border border-emerald-800/50 bg-emerald-950/30 px-4 py-2 text-xs text-emerald-200">
            {{ $bulkMessage }}
        </div>
    @endif

    @if ($uploadError)
        <div role="alert" class="rounded-md border border-rose-800/50 bg-rose-950/30 px-4 py-2 text-xs text-rose-200">
            {{ $uploadError }}
        </div>
    @endif

    @if (! empty($parsed))
        <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-neutral-500">{{ __(':n file(s) ready to review', ['n' => count($parsed)]) }}</span>
            <div class="flex gap-2">
                {{-- Button is the status surface while importAll /
                     importFile is running — disabled + inline spinner,
                     no full-viewport overlay. --}}
                <button type="button" wire:click="importAll"
                        wire:loading.attr="disabled" wire:target="importAll,importFile"
                        class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-wait disabled:opacity-60">
                    <svg wire:loading wire:target="importAll,importFile"
                         class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.35"/>
                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span wire:loading.remove wire:target="importAll,importFile">{{ __('Import all selected') }}</span>
                    <span wire:loading wire:target="importAll,importFile">{{ __('Importing…') }}</span>
                </button>
                <button type="button" wire:click="clearAll"
                        wire:loading.attr="disabled" wire:target="importAll,importFile"
                        class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-300 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-40">
                    {{ __('Clear all') }}
                </button>
            </div>
        </div>

        <div class="space-y-4">
            @foreach ($parsed as $fileId => $state)
                <article wire:key="file-{{ $fileId }}"
                         class="rounded-xl border border-neutral-800 bg-neutral-900/40">
                    <header class="flex items-center justify-between gap-3 border-b border-neutral-800 px-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm text-neutral-100">{{ $state['name'] }}</div>
                            <div class="text-[11px] text-neutral-500">
                                @if ($state['status'] === 'ready')
                                    {{ $state['bank_label'] }}
                                    @if ($state['account_last4']) · {{ __('acct ···:n', ['n' => $state['account_last4']]) }} @endif
                                    @if ($state['period_start'] && $state['period_end']) · {{ $state['period_start'] }} → {{ $state['period_end'] }} @endif
                                    @if (! empty($state['prev_imported_count']))
                                        · <span class="text-amber-400">{{ __('re-scan · :n already on file', ['n' => $state['prev_imported_count']]) }}</span>
                                    @endif
                                @elseif ($state['status'] === 'already_imported')
                                    {{ __('Already imported · :n transactions created earlier', ['n' => $state['prev_imported_count']]) }}
                                @elseif ($state['status'] === 'unrecognized')
                                    {{ __('Format not recognized') }} @if (! empty($state['error'])) — {{ $state['error'] }} @endif
                                @elseif ($state['status'] === 'failed')
                                    <span class="text-rose-300">{{ __('Upload failed') }}</span>
                                @else
                                    {{ __('Parsing…') }}
                                @endif
                            </div>
                        </div>
                        <button type="button" wire:click="dismissFile('{{ $fileId }}')"
                                class="shrink-0 rounded px-2 py-1 text-[11px] text-neutral-400 hover:bg-neutral-800 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            {{ __('Dismiss') }}
                        </button>
                    </header>

                    @if ($state['status'] === 'ready')
                        @php($rows = $state['rows'])
                        @php($selForFile = $selected[$fileId] ?? [])
                        @php($dupForFile = $duplicates[$fileId] ?? [])
                        @php($accountId = $accountFor[$fileId] ?? null)
                        @php($rowCurrency = $this->accounts->firstWhere('id', $accountId)?->currency ?? 'USD')
                        @php($selCount = count(array_filter($selForFile)))
                        <div class="space-y-3 px-4 py-3">
                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label class="block text-[10px] uppercase tracking-wider text-neutral-500">
                                        {{ __('Account') }} <span class="text-rose-400" aria-hidden="true">*</span>
                                    </label>
                                    <select wire:change="setAccount('{{ $fileId }}', $event.target.value || null)"
                                            aria-required="true"
                                            @class([
                                                'mt-1 rounded-md border bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300',
                                                'border-rose-700 focus-visible:border-rose-400' => ! $accountId,
                                                'border-neutral-700 focus-visible:border-neutral-400' => (bool) $accountId,
                                            ])>
                                        <option value="">{{ __('— pick an account —') }}</option>
                                        @foreach ($this->accounts as $a)
                                            <option value="{{ $a->id }}" @selected($accountId == $a->id)>{{ $a->name }}</option>
                                        @endforeach
                                    </select>
                                    @if (! $accountId)
                                        <p class="mt-1 text-[11px] text-rose-400">
                                            {{ __('Required. Transactions won\'t import until an account is set.') }}
                                        </p>
                                    @endif
                                </div>
                                @php($fileYear = $yearFor[$fileId] ?? null)
                                @php($effectiveFileYear = $fileYear ?? $globalYear ?? ($state['detected_year'] ?? null))
                                <div>
                                    <label class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Year') }}</label>
                                    <input type="number" min="2000" max="2099" step="1"
                                           value="{{ $fileYear }}"
                                           placeholder="{{ $effectiveFileYear ?? __('auto') }}"
                                           wire:change="setYearForFile('{{ $fileId }}', $event.target.value === '' ? null : parseInt($event.target.value))"
                                           class="mt-1 w-20 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                </div>
                                @if ($state['opening'] !== null || $state['closing'] !== null)
                                    <div class="text-[11px] text-neutral-500">
                                        {{ __('Open :o · Close :c', ['o' => $state['opening'] !== null ? \App\Support\Formatting::money((float) $state['opening'], $rowCurrency) : '—', 'c' => $state['closing'] !== null ? \App\Support\Formatting::money((float) $state['closing'], $rowCurrency) : '—']) }}
                                    </div>
                                @endif
                                <div class="ml-auto flex items-center gap-2 text-[11px] text-neutral-500">
                                    <button type="button" wire:click="selectAllInFile('{{ $fileId }}', true)"
                                            class="rounded px-2 py-0.5 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ __('Select all') }}
                                    </button>
                                    <button type="button" wire:click="selectAllInFile('{{ $fileId }}', false)"
                                            class="rounded px-2 py-0.5 hover:bg-neutral-800 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        {{ __('Select none') }}
                                    </button>
                                </div>
                            </div>

                            @php($categoryHints = $this->categoryHintsForFile($fileId))
                            @if ($categoryHints)
                                <div class="rounded-md border border-neutral-800 bg-neutral-950/60">
                                    <div class="flex items-baseline justify-between border-b border-neutral-800 px-3 py-2">
                                        <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                                            {{ __('Category hints from the statement') }}
                                        </h3>
                                        <span class="text-[11px] text-neutral-500">
                                            {{ __('Mapped hints auto-categorize; seed patterns once and this list shrinks.') }}
                                        </span>
                                    </div>
                                    <ul class="divide-y divide-neutral-800/60">
                                        @foreach ($categoryHints as $hint)
                                            <li class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                                                <span class="font-medium text-neutral-200">{{ $hint['label'] }}</span>
                                                <span class="text-[11px] text-neutral-500 tabular-nums">{{ $hint['count'] }}×</span>
                                                @if ($hint['status'] === 'mapped')
                                                    <span class="inline-flex items-baseline gap-1.5 rounded bg-emerald-900/30 px-2 py-0.5 text-[11px] text-emerald-300">
                                                        <span>→ {{ $hint['category_name'] }}</span>
                                                        <button type="button"
                                                                wire:click="unmapCategoryHint('{{ $fileId }}', @js($hint['label']))"
                                                                title="{{ __('Unmap — removes the pattern from :c', ['c' => $hint['category_name']]) }}"
                                                                aria-label="{{ __('Unmap :l', ['l' => $hint['label']]) }}"
                                                                class="-mr-1 inline-flex h-4 w-4 items-center justify-center rounded hover:bg-emerald-800/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                            <svg viewBox="0 0 12 12" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                                                <path d="m3 3 6 6M9 3l-6 6"/>
                                                            </svg>
                                                        </button>
                                                    </span>
                                                @else
                                                    {{-- Searchable + addable combobox. Typing filters the
                                                         household categories; picking one calls
                                                         mapCategoryHint so the hint auto-maps next time.
                                                         If the typed text doesn't match anything, a
                                                         "+ Create" row appears that spawns a new category
                                                         using the typed name (falls back to the raw hint
                                                         label when the search box is empty) and seeds
                                                         that category's match_patterns with the original
                                                         source label so future imports hit it. --}}
                                                    <div x-data="{
                                                            q: '',
                                                            open: false,
                                                            active: 0,
                                                            options: @js($this->categories->map(fn ($c) => ['id' => $c->id, 'label' => $c->displayLabel(includeKind: true)])->values()->all()),
                                                            get filtered() {
                                                                const needle = this.q.trim().toLowerCase();
                                                                if (needle === '') return this.options;
                                                                return this.options.filter(o => o.label.toLowerCase().includes(needle));
                                                            },
                                                            get canCreate() {
                                                                const needle = this.q.trim().toLowerCase();
                                                                if (needle === '') return false;
                                                                return ! this.options.some(o => o.label.toLowerCase() === needle);
                                                            },
                                                            pick(id) {
                                                                this.$wire.mapCategoryHint(@js($fileId), @js($hint['label']), id);
                                                                this.open = false;
                                                                this.q = '';
                                                            },
                                                            createNew() {
                                                                const typed = this.q.trim();
                                                                this.$wire.createCategoryFromHint(@js($fileId), @js($hint['label']), typed);
                                                                this.open = false;
                                                                this.q = '';
                                                            },
                                                            activate() {
                                                                if (this.active < this.filtered.length) {
                                                                    this.pick(this.filtered[this.active].id);
                                                                } else if (this.canCreate) {
                                                                    this.createNew();
                                                                }
                                                            },
                                                            move(delta) {
                                                                const total = this.filtered.length + (this.canCreate ? 1 : 0);
                                                                if (total === 0) return;
                                                                this.active = (this.active + delta + total) % total;
                                                            },
                                                         }"
                                                         class="relative"
                                                         x-on:click.outside="open = false">
                                                        <input type="text"
                                                               x-model="q"
                                                               x-on:focus="open = true; active = 0"
                                                               x-on:keydown.arrow-down.prevent="open = true; move(1)"
                                                               x-on:keydown.arrow-up.prevent="move(-1)"
                                                               x-on:keydown.enter.prevent="activate()"
                                                               x-on:keydown.escape.prevent="open = false"
                                                               placeholder="{{ __('Map or type to create…') }}"
                                                               class="w-56 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                        <div x-show="open"
                                                             x-cloak
                                                             x-transition.opacity.duration.75ms
                                                             class="absolute left-0 z-20 mt-1 w-72 overflow-hidden rounded-md border border-neutral-700 bg-neutral-900 shadow-xl">
                                                            <ul class="max-h-56 overflow-y-auto py-1" role="listbox">
                                                                <template x-for="(row, idx) in filtered" :key="row.id">
                                                                    <li role="option"
                                                                        x-on:click="pick(row.id)"
                                                                        x-on:mouseenter="active = idx"
                                                                        :class="active === idx ? 'bg-neutral-800' : ''"
                                                                        class="cursor-pointer px-3 py-1.5 text-sm text-neutral-200">
                                                                        <span x-text="row.label"></span>
                                                                    </li>
                                                                </template>
                                                                <li x-show="canCreate"
                                                                    x-on:click="createNew()"
                                                                    x-on:mouseenter="active = filtered.length"
                                                                    :class="active === filtered.length ? 'bg-neutral-800' : ''"
                                                                    class="cursor-pointer border-t border-neutral-800 px-3 py-1.5 text-sm text-emerald-300">
                                                                    <span x-text="'+ {{ __('Create') }} \'' + q.trim() + '\''"></span>
                                                                </li>
                                                                <li x-show="filtered.length === 0 && ! canCreate" class="px-3 py-2 text-xs text-neutral-500">
                                                                    {{ __('No matches.') }}
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @php($vendorPreview = $this->vendorPreviewForFile($fileId))
                            @php($hintByLabel = collect($categoryHints)->keyBy('label'))
                            @php($categoryResolution = $this->categoryResolutionForFile($fileId))
                            <div class="overflow-x-auto rounded-md border border-neutral-800 bg-neutral-950/60">
                                <table class="w-full text-xs">
                                    <thead class="text-neutral-500">
                                        <tr>
                                            <th class="w-8 px-2 py-1.5"></th>
                                            <th class="w-24 px-2 py-1.5 text-left">{{ __('Date') }}</th>
                                            <th class="px-2 py-1.5 text-left">{{ __('Description') }}</th>
                                            <th class="w-56 px-2 py-1.5 text-left">{{ __('Vendor') }}</th>
                                            <th class="w-40 px-2 py-1.5 text-left">{{ __('Pattern') }}</th>
                                            <th class="w-28 px-2 py-1.5 text-right">{{ __('Amount') }}</th>
                                            <th class="w-20 px-2 py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-800/60">
                                        @foreach ($rows as $i => $row)
                                            @php($isDup = $dupForFile[$i] ?? false)
                                            @php($vp = $vendorPreview[$i] ?? ['status' => 'skip', 'label' => '—'])
                                            @php($isEditing = $editingRow === ($fileId.':'.$i))
                                            @php($overrideId = $row['counterparty_id_override'] ?? null)
                                            @php($vendorLabel = $overrideId ? ($this->contactOptions->firstWhere('id', (int) $overrideId)?->display_name ?? $vp['label']) : $vp['label'])
                                            {{-- Row click enters edit mode when idle. In edit mode,
                                                 the click handler is dropped so typing/interacting
                                                 with the nested inputs doesn't re-trigger. The
                                                 checkbox cell + Update/Cancel buttons use their own
                                                 click handlers with wire:click.stop so they don't
                                                 bubble and toggle edit state. --}}
                                            <tr wire:key="row-{{ $fileId }}-{{ $i }}"
                                                @class([
                                                    $isDup ? 'opacity-60' : '',
                                                    'cursor-pointer transition hover:bg-neutral-800/40' => ! $isEditing,
                                                    'bg-neutral-900/60' => $isEditing,
                                                ])
                                                @if (! $isEditing) wire:click="editRow('{{ $fileId }}', {{ $i }})" @endif>
                                                <td class="px-2 py-1 align-middle">
                                                    <input type="checkbox"
                                                           @checked($selForFile[$i] ?? false)
                                                           wire:click.stop="toggleRow('{{ $fileId }}', {{ $i }})"
                                                           class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                                                           aria-label="{{ __('Select row') }}">
                                                </td>
                                                <td class="px-2 py-1 tabular-nums text-neutral-300">{{ $row['occurred_on'] }}</td>
                                                @if ($isEditing)
                                                    {{-- Enter on any plain input saves + propagates; Esc
                                                         cancels. The searchable-select eats its own Enter
                                                         (for picking a suggestion) — we only bind the
                                                         shortcut on the text/number inputs. --}}
                                                    <td class="px-2 py-1">
                                                        <input wire:model="parsed.{{ $fileId }}.rows.{{ $i }}.description"
                                                               wire:keydown.enter.prevent="saveRow('{{ $fileId }}', {{ $i }})"
                                                               wire:keydown.escape.prevent="cancelEdit"
                                                               type="text" autofocus
                                                               aria-label="{{ __('Description') }}"
                                                               class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                    </td>
                                                    <td class="px-2 py-1 text-xs">
                                                        <x-ui.searchable-select
                                                            id="cp-{{ $fileId }}-{{ $i }}"
                                                            model="parsed.{{ $fileId }}.rows.{{ $i }}.counterparty_id_override"
                                                            :options="['' => '— ' . __('auto') . ' —'] + $this->contactOptions->mapWithKeys(fn ($c) => [(string) $c->id => $c->display_name])->all()"
                                                            :placeholder="__('— auto —')"
                                                            allow-create
                                                            create-method="createCounterpartyForRow"
                                                            edit-inspector-type="contact" />
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        @php($autoFp = $this->descriptionFingerprint((string) ($row['description'] ?? '')))
                                                        <input wire:model="parsed.{{ $fileId }}.rows.{{ $i }}.match_pattern"
                                                               wire:keydown.enter.prevent="saveRow('{{ $fileId }}', {{ $i }})"
                                                               wire:keydown.escape.prevent="cancelEdit"
                                                               type="text"
                                                               aria-label="{{ __('Match pattern') }}"
                                                               placeholder="{{ $autoFp !== '' ? $autoFp : __('auto') }}"
                                                               class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 font-mono text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                    </td>
                                                    <td class="px-2 py-1 text-right tabular-nums">
                                                        <input wire:model="parsed.{{ $fileId }}.rows.{{ $i }}.amount"
                                                               wire:keydown.enter.prevent="saveRow('{{ $fileId }}', {{ $i }})"
                                                               wire:keydown.escape.prevent="cancelEdit"
                                                               type="number" step="0.01"
                                                               aria-label="{{ __('Amount') }}"
                                                               class="w-full rounded border border-neutral-700 bg-neutral-950 px-2 py-1 text-right text-xs tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                    </td>
                                                    <td class="px-2 py-1 text-right">
                                                        <div class="inline-flex items-center gap-1">
                                                            <button type="button" wire:click.stop="saveRow('{{ $fileId }}', {{ $i }})"
                                                                    class="rounded bg-emerald-600 px-2 py-0.5 text-[10px] font-medium text-emerald-50 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                                {{ __('Update') }}
                                                            </button>
                                                            <button type="button" wire:click.stop="cancelEdit"
                                                                    class="rounded border border-neutral-700 px-2 py-0.5 text-[10px] text-neutral-400 hover:text-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                                                {{ __('Cancel') }}
                                                            </button>
                                                        </div>
                                                    </td>
                                                @else
                                                    <td class="px-2 py-1 text-neutral-100">
                                                        {{ $row['description'] }}
                                                        @php($resolved = $categoryResolution[$i] ?? null)
                                                        @php($rowHint = trim((string) ($row['category_hint'] ?? '')))
                                                        @if ($resolved)
                                                            {{-- Final category the transaction will land on.
                                                                 Source badge disambiguates how it was picked so
                                                                 the user can tell "this came from a contact
                                                                 default" vs "this came from the Costco hint". --}}
                                                            @php($sourceLabel = match ($resolved['source']) {
                                                                'contact' => __('via contact'),
                                                                'rule' => __('via rule'),
                                                                'hint' => __('via hint “:h”', ['h' => $rowHint ?: '?']),
                                                                default => '',
                                                            })
                                                            <span class="ml-2 inline-flex items-baseline gap-1 rounded bg-emerald-900/30 px-1.5 py-0.5 text-xs text-emerald-300"
                                                                  title="{{ $sourceLabel }}">
                                                                <span>→ {{ $resolved['category_name'] }}</span>
                                                                <span class="text-[10px] uppercase tracking-wider text-emerald-400/70">{{ $resolved['source'] }}</span>
                                                            </span>
                                                        @elseif ($rowHint !== '')
                                                            {{-- No resolution happened — most commonly because
                                                                 the hint is unmapped. Surface the raw label
                                                                 so the Category-hints panel is easy to act on. --}}
                                                            <span class="ml-2 rounded bg-neutral-800 px-1.5 py-0.5 text-xs text-neutral-400"
                                                                  title="{{ __('Statement category hint — unmapped; use the Category hints panel to seed a pattern.') }}">
                                                                {{ $rowHint }}
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        @if ($overrideId)
                                                            <span class="text-amber-300" title="{{ __('User override') }}">{{ $vendorLabel }}</span>
                                                        @elseif ($vp['status'] === 'existing')
                                                            <span class="text-neutral-300" title="{{ __('Matches an existing contact') }}">{{ $vp['label'] }}</span>
                                                        @elseif ($vp['status'] === 'new')
                                                            <span class="text-emerald-300" title="{{ __('Will auto-create') }}">+ {{ $vp['label'] }}</span>
                                                        @else
                                                            <span class="text-neutral-600">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1 font-mono text-neutral-500">
                                                        @php($explicitPattern = trim((string) ($row['match_pattern'] ?? '')))
                                                        @php($autoPattern = $vp['matched_pattern'] ?? '')
                                                        @php($autoFp = $this->descriptionFingerprint((string) ($row['description'] ?? '')))
                                                        @if ($explicitPattern !== '')
                                                            <span title="{{ __('Row override') }}">{{ $explicitPattern }}</span>
                                                        @elseif ($autoPattern !== '' && ! $overrideId)
                                                            {{-- Vendor was auto-linked via this pattern on the
                                                                 existing contact — surface it so the user can
                                                                 see why without opening the contact. --}}
                                                            <span class="text-neutral-400" title="{{ __('Matched via vendor pattern') }}">{{ $autoPattern }}</span>
                                                        @elseif ($autoFp !== '' && ! $overrideId && ($vp['status'] ?? '') === 'new')
                                                            {{-- Preview of the fingerprint that will seed a
                                                                 newly auto-created vendor. Dimmed to signal
                                                                 "auto" versus an explicit edit. --}}
                                                            <span class="text-neutral-600 italic" title="{{ __('Auto fingerprint (used if a new vendor is created)') }}">{{ $autoFp }}</span>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1 text-right tabular-nums {{ $row['amount'] >= 0 ? 'text-emerald-400' : 'text-neutral-100' }}">
                                                        {{ \App\Support\Formatting::money((float) $row['amount'], $rowCurrency) }}
                                                    </td>
                                                    <td class="px-2 py-1 text-right">
                                                        @if ($isDup)
                                                            <span class="rounded bg-amber-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-amber-300">{{ __('dup') }}</span>
                                                        @endif
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-neutral-500">{{ __(':n of :t selected', ['n' => $selCount, 't' => count($rows)]) }}</span>
                                <button type="button" wire:click="importFile('{{ $fileId }}')"
                                        wire:loading.attr="disabled" wire:target="importAll,importFile"
                                        @disabled(! $accountId || $selCount === 0)
                                        class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-wait disabled:opacity-40">
                                    <svg wire:loading wire:target="importFile('{{ $fileId }}')"
                                         class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.35"/>
                                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    <span wire:loading.remove wire:target="importFile('{{ $fileId }}')">{{ __('Import :n transactions', ['n' => $selCount]) }}</span>
                                    <span wire:loading wire:target="importFile('{{ $fileId }}')">{{ __('Importing…') }}</span>
                                </button>
                            </div>

                            @if (! empty($state['created_count']))
                                <p class="text-[11px] text-emerald-300">{{ __(':n imported from this file', ['n' => $state['created_count']]) }}</p>
                            @endif
                            @php($reasons = $state['skip_reasons'] ?? null)
                            @if ($reasons && (($reasons['duplicate'] ?? 0) + ($reasons['invalid'] ?? 0)) > 0)
                                <p class="text-[11px] text-amber-300">
                                    {{ __('Skipped: ') }}
                                    @if (($reasons['duplicate'] ?? 0) > 0)
                                        {{ __(':n duplicate (same date+amount+account already on file)', ['n' => $reasons['duplicate']]) }}
                                    @endif
                                    @if (($reasons['duplicate'] ?? 0) > 0 && ($reasons['invalid'] ?? 0) > 0) · @endif
                                    @if (($reasons['invalid'] ?? 0) > 0)
                                        {{ __(':n invalid (missing date or amount)', ['n' => $reasons['invalid']]) }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    @elseif ($state['status'] === 'already_imported')
                        <div class="px-4 py-4 text-xs text-neutral-400">
                            {{ __('This file has already been imported. Nothing to do.') }}
                        </div>
                    @elseif ($state['status'] === 'unrecognized')
                        <div class="px-4 py-4 text-xs text-amber-300">
                            {{ __('No parser recognized this file. It\'s saved for manual inspection; no transactions were created.') }}
                        </div>
                    @elseif ($state['status'] === 'failed')
                        <div role="alert" class="px-4 py-4 text-xs text-rose-300">
                            {{ $state['error'] ?? __('Upload failed.') }}
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
