<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Media;
use App\Models\Transaction;
use App\Support\Formatting;
use App\Support\ProjectionMatcher;
use App\Support\Statements\ParsedStatement;
use App\Support\Statements\ParserRegistry;
use App\Support\TransferPairing;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
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

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
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
            } catch (\Throwable $e) {
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

        $tmp = tempnam(sys_get_temp_dir(), 'bureau-zip-');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, $zipBytes);

        $zip = new \ZipArchive;
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
        $householdId = \App\Support\CurrentHousehold::get()?->id;

        // File-level dedup: have we seen this hash before in this household?
        $existingMedia = Media::where('hash', $hash)
            ->when($householdId, fn ($q) => $q->where('household_id', $householdId))
            ->first();
        if ($existingMedia) {
            $prevImportedCount = Transaction::whereHas('media', fn ($q) => $q->where('media.id', $existingMedia->id))
                ->count();
            $this->parsed[$fileId] = [
                'name' => $name,
                'status' => 'already_imported',
                'hash' => $hash,
                'media_id' => $existingMedia->id,
                'prev_imported_count' => $prevImportedCount,
                'rows' => [],
            ];

            return;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $path = 'statements/'.($householdId ?? 0).'/'.date('Y/m').'/'.$fileId.'.'.$ext;
        try {
            Storage::disk('local')->put($path, $bytes);
        } catch (\Throwable $e) {
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

        $registry = app(ParserRegistry::class);
        $stored = Storage::disk('local')->path($path);

        try {
            $statement = $registry->parseFile($stored);
        } catch (\Throwable $e) {
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
            'rows' => $rows,
            'disk_path' => $path,
            'mime' => $mime,
            'size' => strlen($bytes),
        ];

        // Default all rows selected — dedup computation lands once account picked.
        $this->selected[$fileId] = array_fill(0, count($rows), true);
        $this->duplicates[$fileId] = array_fill(0, count($rows), false);

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
     * the picked account. Uses (account + amount ±0.01 + date ±3d) OR same
     * external_id.
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
            $hasFuzzy = ! $hasExternal
                ? Transaction::where('account_id', $accountId)
                    ->whereBetween('amount', [$row['amount'] - 0.005, $row['amount'] + 0.005])
                    ->whereBetween('occurred_on', [
                        \Carbon\CarbonImmutable::parse($row['occurred_on'])->subDays(3)->toDateString(),
                        \Carbon\CarbonImmutable::parse($row['occurred_on'])->addDays(3)->toDateString(),
                    ])
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

    public function dismissFile(string $fileId): void
    {
        if (isset($this->parsed[$fileId]['disk_path']) && ($this->parsed[$fileId]['media_id'] ?? null) === null) {
            try {
                Storage::disk('local')->delete($this->parsed[$fileId]['disk_path']);
            } catch (\Throwable) {
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
        $totalSkipped = 0;
        foreach (array_keys($this->parsed) as $fileId) {
            if (($this->parsed[$fileId]['status'] ?? null) !== 'ready') {
                continue;
            }
            if (! ($this->accountFor[$fileId] ?? null)) {
                continue;
            }
            $created = $this->persistFile($fileId);
            $totalCreated += $created;
            $totalSkipped += ($this->parsed[$fileId]['skipped_count'] ?? 0);
        }

        // Collapse cross-account debit/credit pairs into Transfer records.
        // Keeps net worth honest (same money shouldn't appear on both sides).
        $pairedTransfers = 0;
        $household = \App\Support\CurrentHousehold::get();
        if ($household && $totalCreated > 0) {
            $pairedTransfers = app(TransferPairing::class)->pair($household);
        }

        $this->bulkMessage = $pairedTransfers > 0
            ? __(':c transactions created, :s skipped, :p transfer pair(s) linked.', ['c' => $totalCreated, 's' => $totalSkipped, 'p' => $pairedTransfers])
            : __(':c transactions created, :s skipped.', ['c' => $totalCreated, 's' => $totalSkipped]);
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
        $skipped = 0;

        // Pre-scan: build a fingerprint → Contact id map. Existing contacts
        // get matched, and descriptions that repeat ≥2 times in this batch
        // become new Contacts (kind=org) so future imports auto-link.
        $contactMap = $this->buildContactMap($state['rows']);

        DB::transaction(function () use ($fileId, $state, $accountId, $media, $contactMap, &$created, &$skipped) {
            foreach ($state['rows'] as $i => $row) {
                if (! ($this->selected[$fileId][$i] ?? false)) {
                    continue;
                }
                $externalId = $this->externalIdFor($accountId, $row);
                $fingerprint = $this->descriptionFingerprint((string) $row['description']);
                $counterpartyId = $contactMap[$fingerprint] ?? null;
                try {
                    $txn = Transaction::create([
                        'account_id' => $accountId,
                        'occurred_on' => $row['occurred_on'],
                        'amount' => $row['amount'],
                        'currency' => Account::find($accountId)?->currency ?? 'USD',
                        'description' => $row['description'],
                        'counterparty_contact_id' => $counterpartyId,
                        'status' => 'cleared',
                        'external_id' => $externalId,
                        'import_source' => (string) ($state['import_source'] ?? 'statement:unknown'),
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Unique-constraint collision (account_id, external_id) —
                    // row was imported by a concurrent request or a prior
                    // import we missed in the dedup scan. Count + continue.
                    $skipped++;

                    continue;
                }
                if ($media && ! $txn->media()->where('media.id', $media->id)->exists()) {
                    $txn->media()->attach($media->id, ['role' => 'statement']);
                }
                ProjectionMatcher::attempt($txn);
                $created++;
            }
        });

        $this->parsed[$fileId]['created_count'] = ($this->parsed[$fileId]['created_count'] ?? 0) + $created;
        $this->parsed[$fileId]['skipped_count'] = ($this->parsed[$fileId]['skipped_count'] ?? 0) + $skipped;
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
    private function buildContactMap(array $rows): array
    {
        $counts = [];
        $originals = [];
        foreach ($rows as $row) {
            $fp = $this->descriptionFingerprint((string) $row['description']);
            if ($fp === '') {
                continue;
            }
            $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            if (! isset($originals[$fp])) {
                $originals[$fp] = (string) $row['description'];
            }
        }

        $map = [];
        foreach ($counts as $fp => $count) {
            $existing = Contact::query()
                ->where(function ($q) use ($fp) {
                    $q->whereRaw('LOWER(display_name) LIKE ?', ['%'.$fp.'%'])
                        ->orWhereRaw('LOWER(organization) LIKE ?', ['%'.$fp.'%']);
                })
                ->value('id');

            if ($existing) {
                $map[$fp] = (int) $existing;

                continue;
            }

            // Auto-create only for repeating merchants — keeps contacts clean.
            if ($count >= 2) {
                $contact = Contact::create([
                    'kind' => 'org',
                    'display_name' => $this->humanizeDescription($originals[$fp] ?? $fp),
                    'is_vendor' => true,
                ]);
                $map[$fp] = $contact->id;
            }
        }

        return $map;
    }

    private function descriptionFingerprint(string $raw): string
    {
        $lower = mb_strtolower($raw);
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
        $household = \App\Support\CurrentHousehold::get();

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
};
?>

<div class="space-y-5">
    <header>
        <h2 class="text-base font-semibold text-neutral-100">{{ __('Import statements') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Drop PDF or CSV exports from Wells Fargo, Citi, Amex, or PayPal. Each file is parsed, deduped, and queued for review before anything hits your ledger.') }}
        </p>
    </header>

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
        {{-- Full-screen busy overlay while importAll / importFile / any
             per-row commit is running. Persisting rows is synchronous and
             can take several seconds per file (hundreds of transactions
             + transfer-pairing + duplicate detection), so a page-covering
             status makes the wait legible instead of a frozen UI. --}}
        <div wire:loading.flex wire:target="importAll,importFile"
             role="status" aria-live="polite"
             class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-2 bg-neutral-950/80 text-sm font-medium text-emerald-200 backdrop-blur-sm">
            <svg class="h-8 w-8 animate-spin text-emerald-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span>{{ __('Importing transactions…') }}</span>
            <span class="text-[11px] font-normal text-emerald-300/70">{{ __('Pairing transfers and flagging duplicates as we go.') }}</span>
        </div>

        <div class="flex items-center justify-between gap-3">
            <span class="text-xs text-neutral-500">{{ __(':n file(s) ready to review', ['n' => count($parsed)]) }}</span>
            <div class="flex gap-2">
                <button type="button" wire:click="importAll"
                        wire:loading.attr="disabled" wire:target="importAll,importFile"
                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-wait disabled:opacity-60">
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
                                    <label class="block text-[10px] uppercase tracking-wider text-neutral-500">{{ __('Account') }}</label>
                                    <select wire:change="setAccount('{{ $fileId }}', $event.target.value || null)"
                                            class="mt-1 rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        <option value="">—</option>
                                        @foreach ($this->accounts as $a)
                                            <option value="{{ $a->id }}" @selected($accountId == $a->id)>{{ $a->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @if ($state['opening'] !== null || $state['closing'] !== null)
                                    <div class="text-[11px] text-neutral-500">
                                        {{ __('Open :o · Close :c', ['o' => $state['opening'] !== null ? Formatting::money((float) $state['opening'], $rowCurrency) : '—', 'c' => $state['closing'] !== null ? Formatting::money((float) $state['closing'], $rowCurrency) : '—']) }}
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

                            <div class="overflow-x-auto rounded-md border border-neutral-800 bg-neutral-950/60">
                                <table class="w-full text-xs">
                                    <thead class="text-neutral-500">
                                        <tr>
                                            <th class="px-2 py-1.5"></th>
                                            <th class="px-2 py-1.5 text-left">{{ __('Date') }}</th>
                                            <th class="px-2 py-1.5 text-left">{{ __('Description') }}</th>
                                            <th class="px-2 py-1.5 text-right">{{ __('Amount') }}</th>
                                            <th class="px-2 py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-800/60">
                                        @foreach ($rows as $i => $row)
                                            @php($isDup = $dupForFile[$i] ?? false)
                                            <tr class="{{ $isDup ? 'opacity-60' : '' }}">
                                                <td class="px-2 py-1 align-middle">
                                                    <input type="checkbox"
                                                           @checked($selForFile[$i] ?? false)
                                                           wire:click="toggleRow('{{ $fileId }}', {{ $i }})"
                                                           class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                                                           aria-label="{{ __('Select row') }}">
                                                </td>
                                                <td class="px-2 py-1 tabular-nums text-neutral-300">{{ $row['occurred_on'] }}</td>
                                                <td class="px-2 py-1 text-neutral-100">{{ $row['description'] }}</td>
                                                <td class="px-2 py-1 text-right tabular-nums {{ $row['amount'] >= 0 ? 'text-emerald-400' : 'text-neutral-100' }}">
                                                    {{ Formatting::money((float) $row['amount'], $rowCurrency) }}
                                                </td>
                                                <td class="px-2 py-1">
                                                    @if ($isDup)
                                                        <span class="rounded bg-amber-900/40 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-amber-300">{{ __('already imported') }}</span>
                                                    @endif
                                                </td>
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
                                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:cursor-wait disabled:opacity-40">
                                    <span wire:loading.remove wire:target="importFile('{{ $fileId }}')">{{ __('Import :n transactions', ['n' => $selCount]) }}</span>
                                    <span wire:loading wire:target="importFile('{{ $fileId }}')">{{ __('Importing…') }}</span>
                                </button>
                            </div>

                            @if (! empty($state['created_count']))
                                <p class="text-[11px] text-emerald-300">{{ __(':n imported from this file', ['n' => $state['created_count']]) }}</p>
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
