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
