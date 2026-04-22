<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Media;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Livewire\Livewire;

function extractedPayload(array $overrides = []): array
{
    return array_replace([
        'kind' => 'bill',
        'vendor' => 'Pacific Gas & Electric',
        'amount' => 86.64,
        'currency' => 'USD',
        'issued_on' => '2026-04-17',
        'due_on' => '2026-05-08',
        'tax_amount' => 4.82,
        'line_items' => [],
        'category_suggestion' => 'utilities',
        'confidence' => 0.92,
    ], $overrides);
}

function ocrReadyMedia(array $extraction = []): Media
{
    return Media::create([
        'disk' => 'local',
        'path' => 'scans/bill.png',
        'original_name' => 'bill.png',
        'mime' => 'image/png',
        'size' => 100,
        'ocr_status' => 'done',
        'ocr_text' => 'PG&E April bill',
        'extraction_status' => 'done',
        'ocr_extracted' => extractedPayload($extraction),
    ]);
}

it('prefills the bill form from a Media ocr_extracted payload', function () {
    authedInHousehold();
    $m = ocrReadyMedia();

    Livewire::test('inspector.bill-form', ['mediaId' => $m->id])
        ->assertSet('bill_title', 'Pacific Gas & Electric')
        ->assertSet('amount', '86.64')
        ->assertSet('issued_on', '2026-04-17')
        ->assertSet('due_on', '2026-05-08')
        ->assertSet('currency', 'USD')
        ->assertSet('source_media_id', $m->id);
});

it('prefills the transaction form with a signed amount and issue date', function () {
    authedInHousehold();
    $m = ocrReadyMedia([
        'kind' => 'receipt', 'vendor' => 'Whole Foods', 'amount' => 37.99,
        'issued_on' => '2026-04-15', 'due_on' => null, 'tax_amount' => 2.98,
        'category_suggestion' => 'groceries',
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction', null, $m->id)
        ->assertSet('description', 'Whole Foods')
        ->assertSet('amount', '-37.99')
        ->assertSet('occurred_on', '2026-04-15')
        ->assertSet('tax_amount', '2.98')
        ->assertSet('source_media_id', $m->id);
});

it('auto-resolves counterparty to an existing contact', function () {
    authedInHousehold();
    $c = Contact::create(['kind' => 'org', 'display_name' => 'Whole Foods']);
    $m = ocrReadyMedia(['vendor' => 'Whole Foods', 'category_suggestion' => null]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction', null, $m->id)
        ->assertSet('counterparty_contact_id', $c->id);
});

it('auto-resolves category by suggestion name', function () {
    authedInHousehold();
    $cat = Category::create(['kind' => 'expense', 'name' => 'Utilities', 'slug' => 'utilities']);
    $m = ocrReadyMedia();

    Livewire::test('inspector')
        ->call('openInspector', 'transaction', null, $m->id)
        ->assertSet('category_id', $cat->id);
});

it('attaches the source Media to a saved bill with role=receipt', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $m = ocrReadyMedia();

    Livewire::test('inspector.bill-form', ['mediaId' => $m->id])
        ->set('account_id', $account->id)
        ->call('save');

    $rule = RecurringRule::firstOrFail();
    $pivot = $rule->media()->where('media.id', $m->id)->first()?->pivot;
    expect($pivot)->not->toBeNull()
        ->and($pivot->role)->toBe('receipt');
});

it('attaches the source Media to a saved transaction', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $m = ocrReadyMedia([
        'kind' => 'receipt', 'vendor' => 'Shell', 'amount' => 45.12,
        'issued_on' => '2026-04-10', 'due_on' => null, 'tax_amount' => null,
        'category_suggestion' => null,
    ]);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction', null, $m->id)
        ->set('account_id', $account->id)
        ->set('status', 'cleared')
        ->call('save');

    $t = Transaction::firstOrFail();
    expect($t->media()->where('media.id', $m->id)->exists())->toBeTrue();
});

it('is a no-op when Media has no extracted data', function () {
    authedInHousehold();
    $m = Media::create([
        'disk' => 'local', 'path' => 'blank.png', 'original_name' => 'blank.png',
        'mime' => 'image/png', 'size' => 1, 'ocr_status' => 'done', 'ocr_text' => 'some',
    ]);

    Livewire::test('inspector.bill-form', ['mediaId' => $m->id])
        ->assertSet('bill_title', '')
        ->assertSet('source_media_id', $m->id);
});

it('leaves category and counterparty blank when nothing matches', function () {
    authedInHousehold();
    $m = ocrReadyMedia(['vendor' => 'No Such Vendor', 'category_suggestion' => 'mythical-budget-line']);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction', null, $m->id)
        ->assertSet('counterparty_contact_id', null)
        ->assertSet('category_id', null);
});

it('falls back due_on to issued_on when the document has no due date', function () {
    authedInHousehold();
    $m = ocrReadyMedia(['due_on' => null]);

    Livewire::test('inspector.bill-form', ['mediaId' => $m->id])
        ->assertSet('issued_on', '2026-04-17')
        ->assertSet('due_on', '2026-04-17');
});
