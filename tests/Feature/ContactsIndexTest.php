<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Transaction;
use Livewire\Livewire;

it('sorts by the requested column and toggles direction on repeat click', function () {
    authedInHousehold();

    Contact::create(['kind' => 'person', 'display_name' => 'Alice', 'organization' => 'Zeta LLC']);
    Contact::create(['kind' => 'person', 'display_name' => 'Bob', 'organization' => 'Mu Inc']);
    Contact::create(['kind' => 'person', 'display_name' => 'Charlie', 'organization' => 'Alpha Co']);

    $component = Livewire::test('contacts-index')
        ->assertSet('sortBy', 'display_name')
        ->assertSet('sortDir', 'asc');

    expect($component->instance()->contacts->pluck('display_name')->all())
        ->toBe(['Alice', 'Bob', 'Charlie']);

    // Switching to a different column resets direction to asc.
    $component->call('sort', 'organization')
        ->assertSet('sortBy', 'organization')
        ->assertSet('sortDir', 'asc');

    expect($component->instance()->contacts->pluck('display_name')->all())
        ->toBe(['Charlie', 'Bob', 'Alice']);

    // Clicking the same header again flips direction.
    $component->call('sort', 'organization')
        ->assertSet('sortDir', 'desc');

    expect($component->instance()->contacts->pluck('display_name')->all())
        ->toBe(['Alice', 'Bob', 'Charlie']);
});

it('filters by the vendor role', function () {
    authedInHousehold();

    Contact::create(['kind' => 'org', 'display_name' => 'VendorCo', 'is_vendor' => true]);
    Contact::create(['kind' => 'person', 'display_name' => 'Customer Kim', 'is_customer' => true]);
    Contact::create(['kind' => 'person', 'display_name' => 'Just Someone']);

    $component = Livewire::test('contacts-index')->set('roleFilter', 'vendor');

    $names = $component->instance()->contacts->pluck('display_name')->all();
    expect($names)->toBe(['VendorCo']);
});

it('bulk-deletes the selected contacts', function () {
    authedInHousehold();

    $keep = Contact::create(['kind' => 'person', 'display_name' => 'Keeper']);
    $drop1 = Contact::create(['kind' => 'person', 'display_name' => 'Drop 1']);
    $drop2 = Contact::create(['kind' => 'person', 'display_name' => 'Drop 2']);

    Livewire::test('contacts-index')
        ->set('selected', [$drop1->id, $drop2->id])
        ->call('deleteSelected')
        ->assertSet('selected', []);

    expect(Contact::find($keep->id))->not->toBeNull()
        ->and(Contact::find($drop1->id))->toBeNull()
        ->and(Contact::find($drop2->id))->toBeNull();
});

it('merges selected contacts: winner keeps refs, losers disappear, display_name edit applies', function () {
    authedInHousehold();

    $winner = Contact::create(['kind' => 'org', 'display_name' => 'Chase']);
    $loser = Contact::create(['kind' => 'org', 'display_name' => 'Chase Bank dup']);

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
        'vendor_contact_id' => $loser->id,
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-01-15',
        'amount' => -100.00,
        'currency' => 'USD',
        'description' => 'ATM',
        'status' => 'cleared',
        'counterparty_contact_id' => $loser->id,
    ]);

    Livewire::test('contacts-index')
        ->set('selected', [$winner->id, $loser->id])
        ->call('openMerge')
        ->assertSet('showMerge', true)
        ->assertSet('mergeWinnerId', $winner->id)
        ->set('mergeWinnerName', 'Chase (canonical)')
        ->call('confirmMerge')
        ->assertSet('showMerge', false)
        ->assertSet('selected', []);

    expect(Contact::find($loser->id))->toBeNull()
        ->and(Contact::find($winner->id)?->display_name)->toBe('Chase (canonical)')
        ->and($account->fresh()->vendor_contact_id)->toBe($winner->id)
        ->and(Transaction::where('counterparty_contact_id', $winner->id)->count())->toBe(1);
});

it('accepts a brand-new name for the survivor, not limited to the selected names', function () {
    authedInHousehold();

    $a = Contact::create(['kind' => 'org', 'display_name' => 'ACME', 'favorite' => true]);
    $b = Contact::create(['kind' => 'org', 'display_name' => 'Acme Inc']);
    $c = Contact::create(['kind' => 'org', 'display_name' => 'Acme Corporation']);

    Livewire::test('contacts-index')
        ->set('selected', [$a->id, $b->id, $c->id])
        ->call('openMerge')
        ->set('mergeWinnerName', 'ACME Holdings, LLC')
        ->call('confirmMerge');

    expect(Contact::find($a->id)?->display_name)->toBe('ACME Holdings, LLC')
        ->and(Contact::find($b->id))->toBeNull()
        ->and(Contact::find($c->id))->toBeNull();
});

it('refreshes the name field when the user picks a different winner', function () {
    authedInHousehold();

    $first = Contact::create(['kind' => 'person', 'display_name' => 'Alpha', 'favorite' => true]);
    $second = Contact::create(['kind' => 'person', 'display_name' => 'Beta']);

    Livewire::test('contacts-index')
        ->set('selected', [$first->id, $second->id])
        ->call('openMerge')
        ->assertSet('mergeWinnerName', 'Alpha')
        ->set('mergeWinnerId', $second->id)
        ->assertSet('mergeWinnerName', 'Beta');
});

it('filters to orphaned contacts (no references anywhere)', function () {
    authedInHousehold();
    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);

    $referenced = Contact::create(['kind' => 'org', 'display_name' => 'In use']);
    $orphanA = Contact::create(['kind' => 'org', 'display_name' => 'Old ghost A']);
    $orphanB = Contact::create(['kind' => 'person', 'display_name' => 'Old ghost B']);

    // Pin the "referenced" contact to a Transaction so it stops
    // being an orphan candidate.
    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -10,
        'currency' => 'USD',
        'description' => 'test',
        'status' => 'cleared',
        'counterparty_contact_id' => $referenced->id,
    ]);

    $component = Livewire::test('contacts-index')->set('orphanedOnly', true);
    $ids = $component->instance()->contacts->pluck('id')->sort()->values()->all();

    expect($ids)->toEqualCanonicalizing([$orphanA->id, $orphanB->id])
        ->and($ids)->not->toContain($referenced->id);
});

it('bulk-deletes orphaned contacts via the existing deleteSelected action', function () {
    authedInHousehold();

    $orphanA = Contact::create(['kind' => 'org', 'display_name' => 'Ghost A']);
    $orphanB = Contact::create(['kind' => 'org', 'display_name' => 'Ghost B']);

    Livewire::test('contacts-index')
        ->set('orphanedOnly', true)
        ->set('selected', [$orphanA->id, $orphanB->id])
        ->call('deleteSelected');

    expect(Contact::find($orphanA->id))->toBeNull()
        ->and(Contact::find($orphanB->id))->toBeNull();
});

it('filters contacts by category and surfaces uncategorised via "none"', function () {
    authedInHousehold();
    $groceries = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $dining = Category::create(['kind' => 'expense', 'name' => 'Dining', 'slug' => 'dining']);
    Contact::create(['kind' => 'org', 'display_name' => 'Costco', 'category_id' => $groceries->id]);
    Contact::create(['kind' => 'org', 'display_name' => 'Chipotle', 'category_id' => $dining->id]);
    Contact::create(['kind' => 'org', 'display_name' => 'Uncategorised Inc']);

    $byGroceries = Livewire::test('contacts-index')
        ->set('categoryFilter', (string) $groceries->id)
        ->instance()->contacts->pluck('display_name')->all();
    expect($byGroceries)->toBe(['Costco']);

    $uncategorised = Livewire::test('contacts-index')
        ->set('categoryFilter', 'none')
        ->instance()->contacts->pluck('display_name')->all();
    expect($uncategorised)->toBe(['Uncategorised Inc']);
});

it('filters contacts by a single tag slug', function () {
    authedInHousehold();
    $vipTag = Tag::firstOrCreate(['slug' => 'vip'], ['name' => 'VIP']);
    $c1 = Contact::create(['kind' => 'org', 'display_name' => 'VIP Vendor']);
    Contact::create(['kind' => 'org', 'display_name' => 'Regular Vendor']);
    $c1->tags()->attach($vipTag->id);

    $filtered = Livewire::test('contacts-index')
        ->set('tagFilter', 'vip')
        ->instance()->contacts->pluck('display_name')->all();

    expect($filtered)->toBe(['VIP Vendor']);
});

it('sorts by category column via left-join', function () {
    authedInHousehold();
    $g = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $d = Category::create(['kind' => 'expense', 'name' => 'Dining', 'slug' => 'dining']);
    Contact::create(['kind' => 'org', 'display_name' => 'Z-grocer', 'category_id' => $g->id]);
    Contact::create(['kind' => 'org', 'display_name' => 'A-diner', 'category_id' => $d->id]);

    $names = Livewire::test('contacts-index')
        ->call('sort', 'category')
        ->instance()->contacts->pluck('display_name')->all();

    // Dining < Groceries alphabetically, so A-diner comes first.
    expect($names)->toBe(['A-diner', 'Z-grocer']);
});

it('bulkApplyCategoryToTransactions fills uncategorised txns across every selected contact that has a default category', function () {
    authedInHousehold();

    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $groc = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);
    $dining = Category::create(['kind' => 'expense', 'name' => 'Dining', 'slug' => 'dining']);
    $utilities = Category::create(['kind' => 'expense', 'name' => 'Utilities', 'slug' => 'utilities']);

    $costco = Contact::create(['kind' => 'org', 'display_name' => 'Costco', 'category_id' => $groc->id]);
    $chipotle = Contact::create(['kind' => 'org', 'display_name' => 'Chipotle', 'category_id' => $dining->id]);
    $uncatContact = Contact::create(['kind' => 'org', 'display_name' => 'No-default-cat Inc']);

    // Two uncategorised txns for costco (should be filled) + one already
    // explicitly categorised (should NOT be overwritten).
    Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $costco->id,
        'occurred_on' => now()->toDateString(), 'amount' => -10, 'currency' => 'USD', 'description' => 'Costco 1',
    ]);
    Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $costco->id,
        'occurred_on' => now()->toDateString(), 'amount' => -11, 'currency' => 'USD', 'description' => 'Costco 2',
    ]);
    $explicit = Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $costco->id,
        'occurred_on' => now()->toDateString(), 'amount' => -12, 'currency' => 'USD',
        'description' => 'Costco utilities purchase', 'category_id' => $utilities->id,
    ]);

    // One for chipotle.
    Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $chipotle->id,
        'occurred_on' => now()->toDateString(), 'amount' => -9, 'currency' => 'USD', 'description' => 'Chipotle',
    ]);

    // One for the no-default-category contact — must stay uncategorised.
    $none = Transaction::create([
        'account_id' => $account->id, 'counterparty_contact_id' => $uncatContact->id,
        'occurred_on' => now()->toDateString(), 'amount' => -5, 'currency' => 'USD', 'description' => 'Misc',
    ]);

    Livewire::test('contacts-index')
        ->set('selected', [$costco->id, $chipotle->id, $uncatContact->id])
        ->call('bulkApplyCategoryToTransactions');

    // Costco's two uncategorised rows filled with Groceries, explicit row preserved.
    expect(Transaction::where('counterparty_contact_id', $costco->id)
        ->whereNull('category_id')->count())->toBe(0)
        ->and($explicit->fresh()->category_id)->toBe($utilities->id);

    // Chipotle row filled with Dining.
    expect(Transaction::where('counterparty_contact_id', $chipotle->id)
        ->where('category_id', $dining->id)->count())->toBe(1);

    // No-default-cat contact's row left alone.
    expect($none->fresh()->category_id)->toBeNull();
});

it('paginates the contact list at 50 per page and surfaces total + per-page counts', function () {
    authedInHousehold();

    // 60 contacts — enough to straddle one page boundary.
    foreach (range(1, 60) as $i) {
        Contact::create(['kind' => 'person', 'display_name' => sprintf('Zinc-%02d', $i)]);
    }

    $c = Livewire::test('contacts-index');

    expect($c->instance()->contacts->total())->toBe(60)
        ->and($c->instance()->contacts->perPage())->toBe(50)
        ->and($c->instance()->contacts->count())->toBe(50);

    // Second page holds the remaining 10.
    $c->call('setPage', 2);
    expect($c->instance()->contacts->count())->toBe(10);
});

it('filters contacts by relationship role slug via whereJsonContains', function () {
    authedInHousehold();
    Contact::create(['kind' => 'person', 'display_name' => 'Aunt Sue', 'contact_roles' => ['family']]);
    Contact::create(['kind' => 'person', 'display_name' => 'Bob Colleague', 'contact_roles' => ['colleague']]);
    Contact::create(['kind' => 'person', 'display_name' => 'Both Roles', 'contact_roles' => ['family', 'emergency_contact']]);
    Contact::create(['kind' => 'person', 'display_name' => 'No Roles']);

    $family = Livewire::test('contacts-index')
        ->set('roleFilterSlug', 'family')
        ->instance()->contacts->pluck('display_name')->sort()->values()->all();
    expect($family)->toBe(['Aunt Sue', 'Both Roles']);

    $emergency = Livewire::test('contacts-index')
        ->set('roleFilterSlug', 'emergency_contact')
        ->instance()->contacts->pluck('display_name')->all();
    expect($emergency)->toBe(['Both Roles']);
});

it('refuses to merge when fewer than two contacts are selected', function () {
    authedInHousehold();

    $c = Contact::create(['kind' => 'person', 'display_name' => 'Solo']);

    Livewire::test('contacts-index')
        ->set('selected', [$c->id])
        ->call('openMerge')
        ->assertSet('showMerge', false);
});
