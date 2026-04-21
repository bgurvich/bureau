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

it('filters rows by counterparty_contact_id', function () {
    authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $costco = Contact::create(['kind' => 'org', 'display_name' => 'Costco']);
    $target = Contact::create(['kind' => 'org', 'display_name' => 'Target']);

    $costcoRow = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-07-10',
        'amount' => -50, 'currency' => 'USD', 'description' => 'costco',
        'status' => 'cleared', 'counterparty_contact_id' => $costco->id,
    ]);
    $targetRow = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-07-11',
        'amount' => -20, 'currency' => 'USD', 'description' => 'target',
        'status' => 'cleared', 'counterparty_contact_id' => $target->id,
    ]);
    $orphanRow = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-07-12',
        'amount' => -10, 'currency' => 'USD', 'description' => 'cash',
        'status' => 'cleared',
    ]);

    $component = Livewire::test('transactions-index')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31');

    // Single specific contact.
    $component->set('counterpartyFilter', (string) $costco->id);
    expect($component->instance()->transactions->pluck('id')->all())->toBe([$costcoRow->id]);

    // "none" — rows without a counterparty.
    $component->set('counterpartyFilter', 'none');
    expect($component->instance()->transactions->pluck('id')->all())->toBe([$orphanRow->id]);

    // Empty — all rows.
    $component->set('counterpartyFilter', '');
    expect($component->instance()->transactions->pluck('id')->sort()->values()->all())
        ->toEqualCanonicalizing([$costcoRow->id, $targetRow->id, $orphanRow->id]);
});

it('header checkbox: selectAllVisible adds every visible row; deselectAllVisible drops only those, preserving cross-page picks', function () {
    authedInHousehold();
    [$a, $b, $c] = bulkSeed();

    // Simulate a cross-page pick by seeding an id that isn't in the
    // current visible set. We use a non-existent id so it's a
    // deliberately external token.
    $external = 9999;

    // Widen the date window past the seed rows' future-dated
    // occurred_on values so they're actually "visible" to the
    // paginator when selectAllVisible runs.
    $component = Livewire::test('transactions-index')
        ->set('from', '2026-07-01')
        ->set('to', '2026-07-31')
        ->set('selected', [$external]);

    // Click header when none of the visible are selected → all get added.
    $component->call('selectAllVisible');
    $after = $component->get('selected');
    expect($after)->toEqualCanonicalizing([$external, $a, $b, $c]);

    // Click header again → deselect only visible, keep the external pick.
    $component->call('deselectAllVisible');
    expect($component->get('selected'))->toBe([$external]);
});
