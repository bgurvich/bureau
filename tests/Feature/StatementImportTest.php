<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Media;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function citiCheckingCsv(): string
{
    return <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,Direct Deposit,,1500.00,2500.00
03/10/2026,Rent,1200.00,,1300.00
03/15/2026,Groceries,75.50,,1224.50
CSV;
}

it('renders the import page empty-state', function () {
    authedInHousehold();

    $this->get('/import/statements')
        ->assertOk()
        ->assertSee(__('Import statements'));
});

it('renders the upload-and-parse loader overlay', function () {
    authedInHousehold();

    // The overlay label needs to describe the whole upload + parse phase
    // — bare "Uploading…" undersells the PDF parsing wait. The static
    // markup is emitted with display:none and toggled on by Livewire's
    // wire:loading, so the string ships in the initial HTML.
    $this->get('/import/statements')
        ->assertOk()
        ->assertSee(__('Uploading & parsing files…'))
        ->assertSee(__('PDFs can take a few seconds per file.'));
});

it('parses an uploaded Citi checking CSV and renders a review card', function () {
    authedInHousehold();
    $file = UploadedFile::fake()->createWithContent('citi-checking.csv', citiCheckingCsv());

    $c = Livewire::test('statements-import')
        ->set('files', [$file]);

    $parsed = $c->get('parsed');
    expect($parsed)->toHaveCount(1);
    $first = array_values($parsed)[0];
    expect($first['status'])->toBe('ready')
        ->and($first['bank_slug'])->toBe('citi_checking')
        ->and(count($first['rows']))->toBe(3);
});

it('surfaces the vendor pattern that matched a preview row', function () {
    authedInHousehold();
    Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    // Existing vendor with an explicit pattern — row description should
    // match via pattern, not via fingerprint.
    Contact::create([
        'kind' => 'org',
        'display_name' => 'Landlord LLC',
        'is_vendor' => true,
        'match_patterns' => 'rent',
    ]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    $preview = $c->call('vendorPreviewForFile', $fileId)->get('__return__')
        ?? $c->instance()->vendorPreviewForFile($fileId);

    // "Rent" row picks up the pattern-matched contact and reports the
    // matched pattern string so the preview can display it.
    $rentRow = collect($preview)->firstWhere('label', 'Landlord LLC');
    expect($rentRow)->not->toBeNull()
        ->and($rentRow['matched_pattern'] ?? null)->toBe('rent');
});

it('flags already-imported rows after the user picks an account', function () {
    $user = authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    // Pre-existing row that will conflict on fuzzy amount+date match.
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-03-10',
        'amount' => -1200.00,
        'currency' => 'USD',
        'description' => 'Old rent import',
        'status' => 'cleared',
    ]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileIds = array_keys($c->get('parsed'));
    $fileId = $fileIds[0];
    $c->call('setAccount', $fileId, $account->id);

    $dupes = $c->get('duplicates')[$fileId];
    // Row 1 ("Rent" on 03/10 for -1200) should be flagged.
    expect($dupes)->toContain(true);
});

it('imports selected rows and deterministic external_id prevents re-import duplicates', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id);
    $c->call('importAll');

    expect(Transaction::count())->toBe(3)
        ->and(Media::where('hash', '!=', '')->count())->toBe(1);

    $externalIds = Transaction::pluck('external_id')->filter()->all();
    expect(count(array_unique($externalIds)))->toBe(3);

    // Re-upload same file → file-level dedup flips to already_imported.
    $file2 = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c2 = Livewire::test('statements-import')->set('files', [$file2]);
    $re = array_values($c2->get('parsed'))[0];
    expect($re['status'])->toBe('already_imported')
        ->and($re['prev_imported_count'])->toBe(3);

    // And even if we didn't have file-level dedup, row-level external_id
    // uniqueness prevents duplication.
    expect(Transaction::count())->toBe(3);
});

it('honors per-row counterparty_id_override set in the preview', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $chosen = Contact::create(['kind' => 'org', 'display_name' => 'Manual Pick']);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    // Pin a specific counterparty on row 0 BEFORE importing.
    $c->set("parsed.{$fileId}.rows.0.counterparty_id_override", $chosen->id);
    $c->call('setAccount', $fileId, $account->id);
    $c->call('importAll');

    // Row 0's transaction links to the manually picked contact, not
    // whatever the auto-detect would have produced.
    $first = Transaction::orderBy('occurred_on')->first();
    expect($first->counterparty_contact_id)->toBe($chosen->id);
});

it('persists edited description + amount from the preview into the imported transaction', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    $c->set("parsed.{$fileId}.rows.0.description", 'Edited in preview');
    $c->set("parsed.{$fileId}.rows.0.amount", '-99.99');
    $c->call('setAccount', $fileId, $account->id);
    $c->call('importAll');

    $t = Transaction::where('description', 'Edited in preview')->first();
    expect($t)->not->toBeNull()
        ->and((float) $t->amount)->toBe(-99.99);
});

it('saveRow propagates vendor + pattern to every other row matching the pattern', function () {
    authedInHousehold();
    $chosen = Contact::create(['kind' => 'org', 'display_name' => 'Batch Vendor']);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    // Citi fixture rows: 0=Direct Deposit, 1=Rent, 2=Groceries.
    // Editing row 1 ("Rent") with pattern "rent" should propagate to
    // row 1 itself (no-op) and any OTHER row matching "rent" in its
    // description — none of the others do, so only row 1 has the
    // override. Row 0 and 2 stay null.
    $c->set("parsed.{$fileId}.rows.1.match_pattern", 'rent')
        ->set("parsed.{$fileId}.rows.1.counterparty_id_override", $chosen->id)
        ->call('editRow', $fileId, 1)
        ->call('saveRow', $fileId, 1)
        ->assertSet('editingRow', '');

    $rows = $c->get("parsed.{$fileId}.rows");
    expect($rows[0]['counterparty_id_override'])->toBeNull()
        ->and($rows[1]['counterparty_id_override'])->toBe($chosen->id)
        ->and($rows[2]['counterparty_id_override'])->toBeNull();

    // Now edit row 0 (Direct Deposit) with pattern "e" which is in
    // every description (Deposit, Rent, Groceries) → row 2 picks up
    // the override. Row 1 already had an override — gets overwritten.
    $c->set("parsed.{$fileId}.rows.0.match_pattern", 'e')
        ->set("parsed.{$fileId}.rows.0.counterparty_id_override", $chosen->id)
        ->call('editRow', $fileId, 0)
        ->call('saveRow', $fileId, 0);

    $rows = $c->get("parsed.{$fileId}.rows");
    expect($rows[0]['counterparty_id_override'])->toBe($chosen->id)
        ->and($rows[2]['counterparty_id_override'])->toBe($chosen->id);
});

it('appends the edited match_pattern onto the picked existing vendor on import', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $vendor = Contact::create([
        'kind' => 'org', 'display_name' => 'Acme Corp', 'is_vendor' => true,
        'match_patterns' => 'acme corp',
    ]);

    $csv = <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,ACME*CORP SERVICES 05,12.00,,100.00
CSV;
    $file = UploadedFile::fake()->createWithContent('acme.csv', $csv);
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id);

    // User picks the existing vendor AND types a broader pattern that
    // isn't yet on the contact.
    $c->set("parsed.{$fileId}.rows.0.counterparty_id_override", $vendor->id)
        ->set("parsed.{$fileId}.rows.0.match_pattern", 'acme.*services')
        ->call('importAll');

    expect($vendor->fresh()->match_patterns)->toContain('acme corp')
        ->and($vendor->fresh()->match_patterns)->toContain('acme.*services');
});

it('does not duplicate or append noise when the pattern already matches the vendor', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $vendor = Contact::create([
        'kind' => 'org', 'display_name' => 'Acme', 'is_vendor' => true,
        'match_patterns' => 'acme corp',
    ]);

    $csv = <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,ACME CORP 1,12.00,,100.00
03/06/2026,ACME CORP 2,13.00,,87.00
CSV;
    $file = UploadedFile::fake()->createWithContent('acme.csv', $csv);
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id);

    // Both rows point at the vendor; user leaves "acme corp" pattern
    // (already on contact) on one row and the display-name-fingerprint
    // "acme" (noise) on the other.
    $c->set("parsed.{$fileId}.rows.0.counterparty_id_override", $vendor->id)
        ->set("parsed.{$fileId}.rows.0.match_pattern", 'acme corp')
        ->set("parsed.{$fileId}.rows.1.counterparty_id_override", $vendor->id)
        ->set("parsed.{$fileId}.rows.1.match_pattern", 'acme')
        ->call('importAll');

    // No changes — "acme corp" already present (case-insensitive), "acme"
    // matches display-name fingerprint and is skipped as noise.
    expect($vendor->fresh()->match_patterns)->toBe('acme corp');
});

it('a late-row match_pattern edit is honored on import, not clobbered by the first row', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    // Three rows that share the same description fingerprint. Without the
    // fix, every row pre-seeded match_pattern with the auto fingerprint,
    // so the first row's auto value won the patternOverrides race and the
    // user's explicit edit on a later row was silently ignored.
    $csv = <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,PayPal*RepoHosting 01,10.00,,100.00
03/06/2026,PayPal*RepoHosting 02,10.00,,90.00
03/07/2026,PayPal*RepoHosting 03,10.00,,80.00
CSV;

    $file = UploadedFile::fake()->createWithContent('paypal.csv', $csv);
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id);

    // User edits ONLY the last row's match_pattern to a tightened value.
    // Rows 0 and 1 stay untouched (now blank since we dropped the pre-seed).
    $c->set("parsed.{$fileId}.rows.2.match_pattern", 'repohosting')
        ->call('saveRow', $fileId, 2)
        ->call('importAll');

    // Exactly one Contact was auto-created (≥2 repeats in the fingerprint
    // group). Its match_patterns is the user's edit, not the fingerprint.
    $created = Contact::where('is_vendor', true)->get();
    expect($created)->toHaveCount(1)
        ->and($created->first()->match_patterns)->toBe('repohosting');
});

it('editRow pre-fills counterparty + pattern from the resolved vendor, cancelEdit restores', function () {
    authedInHousehold();
    Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    // Existing vendor whose saved pattern is what the user wants the
    // edit input to show — not a blank "auto" that would mislead them
    // into thinking nothing is wired up.
    $vendor = Contact::create([
        'kind' => 'org',
        'display_name' => 'RepoHosting',
        'is_vendor' => true,
        'match_patterns' => 'paypal repohosting',
    ]);

    $csv = <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,PayPal*RepoHosting Svc,12.00,,100.00
CSV;
    $file = UploadedFile::fake()->createWithContent('paypal.csv', $csv);
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    // Pristine row: no override, no explicit pattern.
    $row = $c->get("parsed.{$fileId}.rows.0");
    expect($row['counterparty_id_override'])->toBeNull()
        ->and($row['match_pattern'])->toBe('');

    $c->call('editRow', $fileId, 0);

    // Entering edit mode pre-fills with the resolved vendor + pattern.
    $row = $c->get("parsed.{$fileId}.rows.0");
    expect($row['counterparty_id_override'])->toBe($vendor->id)
        ->and($row['match_pattern'])->toBe('paypal repohosting');

    // Cancelling rolls back to pristine auto-detect so nothing gets
    // silently locked in just by opening the editor.
    $c->call('cancelEdit');
    $row = $c->get("parsed.{$fileId}.rows.0");
    expect($row['counterparty_id_override'])->toBeNull()
        ->and($row['match_pattern'])->toBe('');
});

it('editRow leaves an already-edited row untouched (no clobber of user edits)', function () {
    authedInHousehold();

    Contact::create([
        'kind' => 'org', 'display_name' => 'RepoHosting', 'is_vendor' => true,
        'match_patterns' => 'paypal repohosting',
    ]);
    $manualPick = Contact::create(['kind' => 'org', 'display_name' => 'Manual pick']);

    $csv = <<<'CSV'
Date,Description,Debit,Credit,Balance
03/05/2026,PayPal*RepoHosting Svc,12.00,,100.00
CSV;
    $file = UploadedFile::fake()->createWithContent('paypal.csv', $csv);
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    // User has already set an explicit override on this row.
    $c->set("parsed.{$fileId}.rows.0.counterparty_id_override", $manualPick->id)
        ->set("parsed.{$fileId}.rows.0.match_pattern", 'my-custom')
        ->call('editRow', $fileId, 0);

    // editRow must NOT overwrite the user's explicit choice with the
    // auto-resolved vendor.
    $row = $c->get("parsed.{$fileId}.rows.0");
    expect($row['counterparty_id_override'])->toBe($manualPick->id)
        ->and($row['match_pattern'])->toBe('my-custom');
});

it('editRow + cancelEdit flips the editing state without touching row data', function () {
    authedInHousehold();

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];

    $c->call('editRow', $fileId, 0)
        ->assertSet('editingRow', "{$fileId}:0")
        ->call('cancelEdit')
        ->assertSet('editingRow', '');
});

it('createCounterpartyForRow creates a Contact and sets the row override', function () {
    authedInHousehold();

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $path = "parsed.{$fileId}.rows.0.counterparty_id_override";

    // Pretend the user typed a fresh vendor name in the dropdown and
    // hit enter → the searchable-select would fire this.
    $c->set("parsed.{$fileId}.rows.0.match_pattern", 'costco');
    $c->call('createCounterpartyForRow', 'Costco Wholesale', $path);

    $created = Contact::where('display_name', 'Costco Wholesale')->first();
    expect($created)->not->toBeNull()
        ->and($created->match_patterns)->toBe('costco')
        ->and($c->get("parsed.{$fileId}.rows.0.counterparty_id_override"))->toBe($created->id);
});

it('re-imports a statement whose transactions were manually wiped', function () {
    // Regression: after DELETE FROM transactions; the Media row for a
    // previously imported statement lingered, and file-level dedup
    // short-circuited the second upload to "already_imported, 0 rows"
    // with no way to re-ingest. parseBytes now treats an existing Media
    // with zero surviving transactions as an orphan and drops it so the
    // normal parse/persist path can run again.
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id)
        ->call('importAll');

    expect(Transaction::count())->toBe(3)
        ->and(Media::where('hash', '!=', '')->count())->toBe(1);

    // Simulate the production wipe.
    Transaction::query()->delete();
    expect(Transaction::count())->toBe(0);

    // Re-upload the same file bytes. Must parse fresh, not short-circuit.
    $file2 = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c2 = Livewire::test('statements-import')->set('files', [$file2]);
    $re = array_values($c2->get('parsed'))[0];
    expect($re['status'])->toBe('ready')
        ->and(count($re['rows']))->toBe(3);

    $reId = array_keys($c2->get('parsed'))[0];
    $c2->call('setAccount', $reId, $account->id)
        ->call('importAll');

    expect(Transaction::count())->toBe(3)
        ->and(Media::where('hash', '!=', '')->count())->toBe(1);
});

it('tracks and surfaces skip reasons (duplicate vs invalid) per file', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    // Pre-existing transaction whose external_id will collide with the
    // first imported row → counted as "duplicate". externalIdFor uses
    // sha1(account|date|description|amount.2f) so match that shape.
    $existingRow = ['occurred_on' => '2026-02-01', 'description' => 'Payroll', 'amount' => 1500.00];
    $externalId = sha1(implode('|', [
        $account->id, $existingRow['occurred_on'], $existingRow['description'], number_format($existingRow['amount'], 2, '.', ''),
    ]));
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => $existingRow['occurred_on'],
        'amount' => $existingRow['amount'],
        'currency' => 'USD',
        'description' => $existingRow['description'],
        'status' => 'cleared',
        'external_id' => $externalId,
    ]);

    $parsed = ['f1' => [
        'name' => 'stmt.pdf', 'status' => 'ready', 'hash' => str_repeat('s', 64),
        'bank_slug' => 'wellsfargo_checking', 'bank_label' => 'WF',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => null, 'period_start' => null, 'period_end' => null,
        'opening' => null, 'closing' => null, 'detected_year' => 2026,
        'disk_path' => 'x', 'mime' => 'application/pdf', 'size' => 1,
        'rows' => [
            // This row will collide with the pre-existing transaction.
            $existingRow + ['closing_balance' => null],
            // Valid row — imports fine.
            ['occurred_on' => '2026-02-05', 'description' => 'Rent', 'amount' => -1200.00, 'closing_balance' => null],
            // Invalid row — missing date.
            ['occurred_on' => '', 'description' => 'Broken', 'amount' => -50.00, 'closing_balance' => null],
        ],
    ]];

    $c = Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->set('accountFor', ['f1' => $account->id])
        ->set('selected', ['f1' => [true, true, true]])
        ->call('importAll');

    $reasons = $c->get('parsed')['f1']['skip_reasons'];
    expect($reasons['duplicate'])->toBe(1)
        ->and($reasons['invalid'])->toBe(1)
        ->and($c->get('bulkMessage'))->toContain('1 skipped as duplicates')
        ->and($c->get('bulkMessage'))->toContain('1 skipped with missing date');
});

it('applies a per-file year override to every row', function () {
    authedInHousehold();

    $parsed = ['f1' => [
        'name' => 'wf-statement.pdf',
        'status' => 'ready',
        'hash' => str_repeat('y', 64),
        'bank_slug' => 'wellsfargo_checking',
        'bank_label' => 'Wells Fargo — Checking',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => null,
        'period_start' => null, 'period_end' => null,
        'opening' => null, 'closing' => null,
        'detected_year' => 2026,
        'disk_path' => 'statements/fake.pdf', 'mime' => 'application/pdf', 'size' => 1,
        'rows' => [
            ['occurred_on' => '2026-01-22', 'description' => 'A', 'amount' => -10.00, 'closing_balance' => null],
            ['occurred_on' => '2026-02-05', 'description' => 'B', 'amount' => -20.00, 'closing_balance' => null],
        ],
    ]];

    $c = Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->call('setYearForFile', 'f1', 2024);

    $rows = $c->get('parsed')['f1']['rows'];
    expect($rows[0]['occurred_on'])->toBe('2024-01-22')
        ->and($rows[1]['occurred_on'])->toBe('2024-02-05');
});

it('falls back to the global year when a file has no per-file override', function () {
    authedInHousehold();

    $parsed = ['f1' => [
        'name' => 'wf.pdf', 'status' => 'ready', 'hash' => str_repeat('z', 64),
        'bank_slug' => 'wellsfargo_checking', 'bank_label' => 'WF',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => null, 'period_start' => null, 'period_end' => null,
        'opening' => null, 'closing' => null, 'detected_year' => 2026,
        'disk_path' => 'x', 'mime' => 'application/pdf', 'size' => 1,
        'rows' => [
            ['occurred_on' => '2026-03-15', 'description' => 'X', 'amount' => -5.00, 'closing_balance' => null],
        ],
    ]];

    $c = Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->set('globalYear', 2023);

    expect($c->get('parsed')['f1']['rows'][0]['occurred_on'])->toBe('2023-03-15');
});

it('surfaces "account required" in the bulk message when a file has none assigned', function () {
    authedInHousehold();

    // One ready file, no account → importAll silently skipped it before;
    // now the bulk message calls out the gap so the user knows what to do.
    $parsed = ['f1' => [
        'name' => 'missing-acct.pdf',
        'status' => 'ready',
        'hash' => str_repeat('b', 64),
        'bank_slug' => 'wellsfargo_checking',
        'bank_label' => 'Wells Fargo — Checking',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => null,
        'period_start' => null,
        'period_end' => null,
        'opening' => null,
        'closing' => null,
        'disk_path' => 'statements/fake.pdf',
        'mime' => 'application/pdf',
        'size' => 1,
        'rows' => [
            ['occurred_on' => '2026-01-22', 'description' => 'Test', 'amount' => -10.00, 'closing_balance' => null],
        ],
    ]];

    $c = Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->set('accountFor', [])
        ->set('selected', ['f1' => [true]])
        ->call('importAll');

    expect($c->get('bulkMessage'))->toContain('1 file')
        ->and($c->get('bulkMessage'))->toContain('account');
    expect(Transaction::count())->toBe(0);
});

it('reuses one vendor Contact for ATM rows whose suffix varies per transaction', function () {
    // Regression: ATM-withdrawal descriptions carry variable address
    // tails like "Non-WF ATM Withdrawal 4326 Main St" and "Non-WF ATM
    // Withdrawal 5678 Oak Ave". The old fingerprint kept words past
    // the first digit, so the two rows fingerprinted differently
    // ("withdrawal main" vs "withdrawal"), each spawning its own
    // Contact even though humanizeDescription collapsed them to the
    // same display_name. The fingerprint now cuts at the first digit
    // to match humanize's scope.
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $rows = [
        ['occurred_on' => '2026-01-05', 'description' => 'Non-WF ATM Withdrawal 4326 Main St', 'amount' => -100.00, 'closing_balance' => null],
        ['occurred_on' => '2026-01-12', 'description' => 'Non-WF ATM Withdrawal 5678 Oak Ave', 'amount' => -200.00, 'closing_balance' => null],
        ['occurred_on' => '2026-01-20', 'description' => 'Non-WF ATM Withdrawal 8817',        'amount' => -40.00,  'closing_balance' => null],
    ];
    $parsed = ['f1' => [
        'name' => 'stmt.pdf', 'status' => 'ready', 'hash' => str_repeat('w', 64),
        'bank_slug' => 'wellsfargo_checking', 'bank_label' => 'WF',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => null, 'period_start' => null, 'period_end' => null,
        'opening' => null, 'closing' => null, 'detected_year' => 2026,
        'disk_path' => 'x', 'mime' => 'application/pdf', 'size' => 1,
        'rows' => $rows,
    ]];

    Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->set('accountFor', ['f1' => $account->id])
        ->set('selected', ['f1' => [true, true, true]])
        ->call('importFile', 'f1');

    // All three rows share the same merchant → exactly one Contact.
    expect(Contact::count())->toBe(1);
    $contactId = Contact::first()->id;
    $linked = Transaction::pluck('counterparty_contact_id')->filter()->unique()->values();
    expect($linked->count())->toBe(1)
        ->and($linked->first())->toBe($contactId);
});

it('reuses the same vendor Contact across statement batches', function () {
    // Regression: buildContactMap ran a substring LIKE against
    // display_name — but the fingerprint skips short words while
    // humanizeDescription keeps them, so the fingerprint never
    // substring-matched its own auto-created contact on re-import. Every
    // batch created a fresh duplicate. Fix fingerprints both sides in
    // PHP and matches on equality.
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $rowsOne = [
        ['occurred_on' => '2026-01-05', 'description' => 'WF Home Mtg Auto Pay 012218 0302976428 Boris Gurvich', 'amount' => -907.62, 'closing_balance' => null],
        ['occurred_on' => '2026-01-22', 'description' => 'WF Home Mtg Auto Pay 012218 0383322369 Boris Gurvich', 'amount' => -907.62, 'closing_balance' => null],
    ];
    $rowsTwo = [
        ['occurred_on' => '2026-02-05', 'description' => 'WF Home Mtg Auto Pay 022518 0302976428 Boris Gurvich', 'amount' => -907.62, 'closing_balance' => null],
        ['occurred_on' => '2026-02-22', 'description' => 'WF Home Mtg Auto Pay 022518 0383322369 Boris Gurvich', 'amount' => -907.62, 'closing_balance' => null],
    ];
    $make = fn (string $id, array $rows) => [$id => [
        'name' => "stmt-{$id}.pdf",
        'status' => 'ready',
        'hash' => str_repeat(substr($id, 0, 1), 64),
        'bank_slug' => 'wellsfargo_checking',
        'bank_label' => 'Wells Fargo — Checking',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => '1234',
        'period_start' => null, 'period_end' => null,
        'opening' => null, 'closing' => null,
        'disk_path' => 'statements/fake.pdf',
        'mime' => 'application/pdf',
        'size' => 1,
        'rows' => $rows,
    ]];

    Livewire::test('statements-import')
        ->set('parsed', $make('f1', $rowsOne))
        ->set('accountFor', ['f1' => $account->id])
        ->set('selected', ['f1' => [true, true]])
        ->call('importFile', 'f1');

    $vendorsAfterOne = Contact::count();
    expect($vendorsAfterOne)->toBe(1);

    // Second batch, same merchant description. The fingerprint lookup
    // should find the already-created contact and NOT make a new one.
    Livewire::test('statements-import')
        ->set('parsed', $make('f2', $rowsTwo))
        ->set('accountFor', ['f2' => $account->id])
        ->set('selected', ['f2' => [true, true]])
        ->call('importFile', 'f2');

    expect(Contact::count())->toBe(1);

    // And every transaction points at the same contact.
    $contactIds = Transaction::pluck('counterparty_contact_id')->filter()->unique()->values();
    expect($contactIds->count())->toBe(1);
});

it('persists the statement running balance onto each Transaction row', function () {
    // For statements that print "Ending daily balance" per row (WF
    // checking Activity Summary layout), the balance comes off the
    // parser on ParsedTransaction::runningBalance, rides through the
    // Livewire $parsed.rows[*].closing_balance payload, and lands in
    // transactions.closing_balance — giving reconciliation a truth
    // oracle per row without needing to reconstruct it from scratch.
    //
    // We bypass upload + parse here (PDF extraction needs a real binary
    // we don't ship as a fixture) and drive the persist path directly
    // by injecting a ready-state into the component.
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $rows = [
        ['occurred_on' => '2026-01-22', 'description' => 'WF Home Mtg Auto Pay', 'amount' => -907.62, 'closing_balance' => 4238.11],
        ['occurred_on' => '2026-01-23', 'description' => 'Direct Dep Acme Payroll', 'amount' => 2500.00, 'closing_balance' => 6738.11],
        ['occurred_on' => '2026-01-25', 'description' => 'Uni021-Unirgy Ll Dirdep', 'amount' => 9548.91, 'closing_balance' => 16287.02],
        ['occurred_on' => '2026-01-30', 'description' => 'ATM Withdrawal', 'amount' => -200.00, 'closing_balance' => 16087.02],
    ];
    $parsed = ['f1' => [
        'name' => 'wf.pdf',
        'status' => 'ready',
        'hash' => str_repeat('a', 64),
        'bank_slug' => 'wellsfargo_checking',
        'bank_label' => 'Wells Fargo — Checking',
        'import_source' => 'statement:wellsfargo_checking',
        'account_last4' => '1234',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'opening' => 3050.63,
        'closing' => 15737.02,
        'disk_path' => 'statements/fake.pdf',
        'mime' => 'application/pdf',
        'size' => 1,
        'rows' => $rows,
    ]];

    Livewire::test('statements-import')
        ->set('parsed', $parsed)
        ->set('accountFor', ['f1' => $account->id])
        ->set('selected', ['f1' => array_fill(0, count($rows), true)])
        ->call('importFile', 'f1');

    $byDate = Transaction::orderBy('occurred_on')->get()->keyBy(fn ($t) => $t->occurred_on->toDateString());
    expect($byDate->count())->toBe(4)
        ->and((float) $byDate['2026-01-22']->closing_balance)->toBe(4238.11)
        ->and((float) $byDate['2026-01-23']->closing_balance)->toBe(6738.11)
        ->and((float) $byDate['2026-01-25']->closing_balance)->toBe(16287.02)
        ->and((float) $byDate['2026-01-30']->closing_balance)->toBe(16087.02);
});

it('attaches the uploaded file as Media with role=statement on every created Transaction', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());
    $c = Livewire::test('statements-import')->set('files', [$file]);
    $fileId = array_keys($c->get('parsed'))[0];
    $c->call('setAccount', $fileId, $account->id)
        ->call('importAll');

    $media = Media::where('hash', '!=', '')->firstOrFail();
    expect($media->source)->toBe('upload');
    foreach (Transaction::all() as $t) {
        expect($t->media()->where('media.id', $media->id)->wherePivot('role', 'statement')->exists())->toBeTrue();
    }
});

it('unrecognized file writes no Transactions and reports status', function () {
    authedInHousehold();
    // Non-bank CSV
    $content = "foo,bar,baz\n1,2,3";
    $file = UploadedFile::fake()->createWithContent('garbage.csv', $content);

    $c = Livewire::test('statements-import')->set('files', [$file]);
    $state = array_values($c->get('parsed'))[0];
    expect($state['status'])->toBe('unrecognized');
    expect(Transaction::count())->toBe(0);
});

it('auto-assigns the default account to every newly parsed file', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $a = UploadedFile::fake()->createWithContent('citi-a.csv', citiCheckingCsv());
    $b = UploadedFile::fake()->createWithContent('citi-b.csv', citiCheckingCsv());

    $c = Livewire::test('statements-import')
        ->set('defaultAccountId', $account->id)
        ->set('files', [$a, $b]);

    $accountFor = $c->get('accountFor');
    expect($accountFor)->toHaveCount(2);
    foreach ($accountFor as $accountId) {
        expect((int) $accountId)->toBe($account->id);
    }
});

it('retroactively applies a newly-picked default account to already-parsed cards without one', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    $file = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());

    $c = Livewire::test('statements-import')
        ->set('files', [$file]) // uploaded first, no default yet
        ->set('defaultAccountId', $account->id); // then pick the default

    $accountFor = $c->get('accountFor');
    $fileId = array_keys($c->get('parsed'))[0];
    expect((int) $accountFor[$fileId])->toBe($account->id);
});

it('caps a batch at 20 files and surfaces an upload error for the remainder', function () {
    authedInHousehold();

    $files = [];
    for ($i = 0; $i < 25; $i++) {
        $files[] = UploadedFile::fake()->createWithContent("citi-{$i}.csv", citiCheckingCsv());
    }

    $c = Livewire::test('statements-import')->set('files', $files);

    // First 20 parsed successfully; 5 dropped with a loud batch-level error.
    $parsed = $c->get('parsed');
    expect(count($parsed))->toBe(20);
    $error = $c->get('uploadError');
    expect($error)->toContain('5')->and($error)->toContain('ignored');
});

it('flags a parser that recognises the file but extracts zero transactions as failed', function () {
    authedInHousehold();

    // Citi CSV with just headers — parser recognises shape, yields 0 rows.
    $file = UploadedFile::fake()->createWithContent(
        'empty-citi.csv',
        "Date,Description,Debit,Credit,Balance\n",
    );

    $c = Livewire::test('statements-import')->set('files', [$file]);
    $parsed = $c->get('parsed');
    $state = array_values($parsed)[0];

    // Old bug: this state used to be status=ready with rows=[]. UI rendered an
    // empty verification table that reads as "imported nothing." Now it must
    // surface loudly as a failure with an explanation.
    expect($state['status'])->toBe('failed')
        ->and($state['error'])->not->toBeEmpty()
        ->and($state['rows'])->toBe([]);
});

it('continues the batch when one file throws during parsing', function () {
    authedInHousehold();

    // Empty file — `file_get_contents` returns '' (empty string, not false).
    // Parsers should handle gracefully; if any crashes, the catch inside
    // updatedFiles keeps the rest of the batch moving.
    $broken = UploadedFile::fake()->create('corrupt.pdf', 0, 'application/pdf');
    $ok = UploadedFile::fake()->createWithContent('citi.csv', citiCheckingCsv());

    $c = Livewire::test('statements-import')->set('files', [$broken, $ok]);
    $parsed = $c->get('parsed');

    // The good file parsed successfully regardless of what happened to the bad one.
    expect(count($parsed))->toBe(2);
    $statuses = array_map(fn ($s) => $s['status'], array_values($parsed));
    expect($statuses)->toContain('ready');
});

it('unpacks a ZIP archive and parses each inner CSV as its own card', function () {
    authedInHousehold();

    $zipPath = tempnam(sys_get_temp_dir(), 'stmts-').'.zip';
    $zip = new ZipArchive;
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBe(true);
    $zip->addFromString('citi-march.csv', citiCheckingCsv());
    $zip->addFromString('citi-april.csv', "Date,Description,Debit,Credit,Balance\n04/05/2026,Coffee,4.50,,995.50\n");
    $zip->addFromString('__MACOSX/._meta', 'junk');
    $zip->addFromString('notes.txt', 'ignore me');
    $zip->close();
    $zipBytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    $file = UploadedFile::fake()->createWithContent('statements.zip', $zipBytes);
    $c = Livewire::test('statements-import')->set('files', [$file]);

    $parsed = $c->get('parsed');
    expect(count($parsed))->toBe(2);
    foreach ($parsed as $state) {
        expect($state['status'])->toBe('ready')
            ->and($state['bank_slug'])->toBe('citi_checking');
    }
});
