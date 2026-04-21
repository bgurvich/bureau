<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Transaction;
use Livewire\Livewire;

function bulkSeed(): array
{
    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => '2026-07-'.(10 + $i),
            'amount' => -10 * $i,
            'currency' => 'USD',
            'description' => "Row {$i}",
            'status' => 'cleared',
        ])->id;
    }

    return $ids;
}

it('marks selected transactions as pending via bulkMarkPending', function () {
    authedInHousehold();
    [$a, $b, $c] = bulkSeed();

    Livewire::test('transactions-index')
        ->set('selected', [$a, $c])
        ->call('bulkMarkPending');

    expect(Transaction::find($a)->status)->toBe('pending')
        ->and(Transaction::find($b)->status)->toBe('cleared')
        ->and(Transaction::find($c)->status)->toBe('pending');
});

it('marks selected transactions as cleared via bulkMarkCleared', function () {
    authedInHousehold();
    [$a, $b, $c] = bulkSeed();
    Transaction::whereIn('id', [$a, $c])->update(['status' => 'pending']);

    Livewire::test('transactions-index')
        ->set('selected', [$a, $c])
        ->call('bulkMarkCleared');

    expect(Transaction::find($a)->status)->toBe('cleared')
        ->and(Transaction::find($c)->status)->toBe('cleared');
});

it('applies category + counterparty + appends tags via bulk edit modal', function () {
    authedInHousehold();
    [$a, $b, $c] = bulkSeed();

    $cat = Category::create(['kind' => 'expense', 'slug' => 'groceries', 'name' => 'Groceries']);
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Costco']);

    // Pre-existing tag on one of the rows — must survive bulk append.
    $existing = Tag::firstOrCreate(['slug' => 'keepme'], ['name' => 'keepme']);
    Transaction::find($a)->tags()->attach($existing->id);

    Livewire::test('transactions-index')
        ->set('selected', [$a, $c])
        ->call('openBulkEdit')
        ->set('bulkCategoryId', $cat->id)
        ->set('bulkCounterpartyId', $contact->id)
        ->set('bulkTagsToAdd', '#tax-2026 groceries')
        ->call('applyBulkEdit')
        ->assertSet('showBulkEdit', false);

    $aFresh = Transaction::with('tags:id,slug')->find($a);
    $bFresh = Transaction::find($b);
    $cFresh = Transaction::with('tags:id,slug')->find($c);

    expect($aFresh->category_id)->toBe($cat->id)
        ->and($aFresh->counterparty_contact_id)->toBe($contact->id)
        ->and($aFresh->tags->pluck('slug')->all())->toEqualCanonicalizing(['keepme', 'tax-2026', 'groceries'])
        ->and($bFresh->category_id)->toBeNull()
        ->and($cFresh->category_id)->toBe($cat->id)
        ->and($cFresh->tags->pluck('slug')->all())->toEqualCanonicalizing(['tax-2026', 'groceries']);
});

it('leaves unset fields alone on bulk edit', function () {
    authedInHousehold();
    [$a] = bulkSeed();

    $cat = Category::create(['kind' => 'expense', 'slug' => 'dining', 'name' => 'Dining']);
    Transaction::whereKey($a)->update(['category_id' => $cat->id]);

    Livewire::test('transactions-index')
        ->set('selected', [$a])
        ->call('openBulkEdit')
        ->set('bulkTagsToAdd', 'travel')
        ->call('applyBulkEdit');

    $fresh = Transaction::with('tags:id,slug')->find($a);
    expect($fresh->category_id)->toBe($cat->id)
        ->and($fresh->tags->pluck('slug')->all())->toBe(['travel']);
});

it('toggles a row into and out of selected', function () {
    authedInHousehold();
    [$a] = bulkSeed();

    Livewire::test('transactions-index')
        ->call('toggleRow', $a)
        ->assertSet('selected', [$a])
        ->call('toggleRow', $a)
        ->assertSet('selected', []);
});
