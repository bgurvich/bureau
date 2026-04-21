<?php

use App\Models\Account;
use App\Models\MailIngestInbox;
use App\Models\Media;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Livewire\Livewire;

function unprocessedMedia(array $ocrExtracted = []): Media
{
    return Media::create([
        'disk' => 'local', 'source' => 'mail',
        'path' => 'scans/bill.png', 'original_name' => 'bill.png',
        'mime' => 'image/png', 'size' => 100,
        'ocr_status' => 'done', 'ocr_text' => 'stuff',
        'extraction_status' => 'done',
        'ocr_extracted' => array_replace([
            'vendor' => 'PG&E', 'amount' => 86.64, 'issued_on' => '2026-04-17',
            'category_suggestion' => 'utilities',
        ], $ocrExtracted),
    ]);
}

it('renders Inbox zero when nothing awaits action', function () {
    authedInHousehold();
    $this->get('/inbox')
        ->assertOk()
        ->assertSee('Inbox zero');
});

it('lists unprocessed media with OCR extraction', function () {
    authedInHousehold();
    unprocessedMedia();
    unprocessedMedia(['vendor' => 'Acme'])->forceFill(['processed_at' => now()])->save();

    $c = Livewire::test('inbox');
    $items = $c->get('items');
    expect($items)->toHaveCount(1)
        ->and($items->pluck('title')->all())->toContain('PG&E')
        ->and($items->pluck('title')->all())->not->toContain('Acme');
});

it('dismisses a single scan without creating a record', function () {
    authedInHousehold();
    $m = unprocessedMedia();

    Livewire::test('inbox')
        ->call('dismissOne', $m->id);

    expect($m->fresh()->processed_at)->not->toBeNull();
});

it('bulk-dismisses selected scans', function () {
    authedInHousehold();
    $m1 = unprocessedMedia();
    $m2 = unprocessedMedia(['vendor' => 'Other']);

    Livewire::test('inbox')
        ->call('toggle', $m1->id)
        ->call('toggle', $m2->id)
        ->call('dismissSelected');

    expect($m1->fresh()->processed_at)->not->toBeNull()
        ->and($m2->fresh()->processed_at)->not->toBeNull();
});

it('selectAll marks every visible row', function () {
    authedInHousehold();
    unprocessedMedia();
    unprocessedMedia(['vendor' => 'B']);

    $c = Livewire::test('inbox')->call('selectAll');
    expect(count($c->get('selected')))->toBe(2);
});

it('filters by source', function () {
    authedInHousehold();
    unprocessedMedia(); // source=mail
    $upload = unprocessedMedia(['vendor' => 'UploadVendor']);
    $upload->forceFill(['source' => 'upload'])->save();

    Livewire::test('inbox')
        ->set('sourceFilter', 'mail')
        ->assertSee('PG&E')
        ->assertDontSee('UploadVendor');
});

it('creating a bill from a scan marks the media processed', function () {
    authedInHousehold();
    $m = unprocessedMedia();
    $account = Account::create([
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'bill', null, $m->id)
        ->set('account_id', $account->id)
        ->call('save');

    expect(RecurringRule::count())->toBe(1)
        ->and($m->fresh()->processed_at)->not->toBeNull();
});

it('already-attached scans are excluded from the Inbox', function () {
    authedInHousehold();
    $m = unprocessedMedia();
    $account = Account::create([
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'bill', null, $m->id)
        ->set('account_id', $account->id)
        ->call('save');

    $c = Livewire::test('inbox');
    expect($c->get('items'))->toHaveCount(0);
});

it('dismiss + reopen toggle processed_at on /media preview', function () {
    authedInHousehold();
    $m = unprocessedMedia();

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->call('dismissProcessing');
    expect($m->fresh()->processed_at)->not->toBeNull();

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->call('reopenProcessing');
    expect($m->fresh()->processed_at)->toBeNull();
});

it('MailIngester sets media.source=mail on attachments it persists', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'bills@bureau.app', 'active' => true]);

    $this->postJson('/webhooks/postmark/inbound', [
        'From' => 'a@x.com', 'FromName' => 'A',
        'ToFull' => [['Email' => 'bills@bureau.app']],
        'Subject' => 'hi', 'MessageID' => 'p1',
        'Headers' => [['Name' => 'Message-ID', 'Value' => '<pm1@x>']],
        'Date' => 'Thu, 17 Apr 2026 10:30:00 +0000',
        'TextBody' => '', 'HtmlBody' => '',
        'Attachments' => [[
            'Name' => 'r.png', 'Content' => base64_encode('x'),
            'ContentType' => 'image/png', 'ContentLength' => 1,
        ]],
    ])->assertOk();

    expect(Media::firstOrFail()->source)->toBe('mail');
});

it('bulk-creates transactions from selected scans with per-scan extraction', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0]);
    $m1 = unprocessedMedia(['vendor' => 'Acme', 'amount' => 25.00, 'issued_on' => '2026-04-10', 'tax_amount' => 1.50]);
    $m2 = unprocessedMedia(['vendor' => 'Beta', 'amount' => 12.34, 'issued_on' => '2026-04-11', 'tax_amount' => null]);
    // No amount → must be skipped
    $m3 = unprocessedMedia(['vendor' => 'Mystery', 'amount' => null]);

    Livewire::test('inbox')
        ->call('toggle', $m1->id)
        ->call('toggle', $m2->id)
        ->call('toggle', $m3->id)
        ->call('openBulkTxnForm')
        ->set('bulk_account_id', $account->id)
        ->call('bulkCreateTransactions');

    expect(Transaction::count())->toBe(2);
    $txns = Transaction::orderBy('id')->get();
    expect((float) $txns[0]->amount)->toBe(-25.00)
        ->and($txns[0]->description)->toBe('Acme')
        ->and($txns[0]->occurred_on->toDateString())->toBe('2026-04-10')
        ->and((float) $txns[0]->tax_amount)->toBe(1.50)
        ->and($txns[0]->account_id)->toBe($account->id);

    expect($m1->fresh()->processed_at)->not->toBeNull()
        ->and($m2->fresh()->processed_at)->not->toBeNull()
        ->and($m3->fresh()->processed_at)->toBeNull();
});

it('bulk create rejects when no account is chosen', function () {
    authedInHousehold();
    $m = unprocessedMedia();
    Livewire::test('inbox')
        ->call('toggle', $m->id)
        ->call('openBulkTxnForm')
        ->call('bulkCreateTransactions')
        ->assertHasErrors(['bulk_account_id']);
    expect(Transaction::count())->toBe(0);
});

it('bulk delete removes media rows', function () {
    authedInHousehold();
    $m1 = unprocessedMedia();
    $m2 = unprocessedMedia(['vendor' => 'Other']);

    Livewire::test('inbox')
        ->call('toggle', $m1->id)
        ->call('toggle', $m2->id)
        ->call('bulkDelete');

    expect(Media::find($m1->id))->toBeNull()
        ->and(Media::find($m2->id))->toBeNull();
});
