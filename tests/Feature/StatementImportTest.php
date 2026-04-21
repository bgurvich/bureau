<?php

use App\Models\Account;
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
