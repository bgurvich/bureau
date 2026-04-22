<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\RecurringProjection;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function setupForWorkbench(): Account
{
    authedInHousehold();

    return Account::create([
        'type' => 'checking', 'name' => 'Chase', 'currency' => 'USD', 'opening_balance' => 0,
    ]);
}

it('renders an empty-state when nothing needs reconciling', function () {
    authedInHousehold();

    $this->get('/reconcile')
        ->assertOk()
        ->assertSee(__('Reconciliation workbench'))
        ->assertSee(__('Nothing to reconcile. Everything is clean.'));
});

it('surfaces an overdue unmatched projection', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    $account = setupForWorkbench();

    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'Overdue rent',
        'amount' => -2000, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        'dtstart' => '2026-04-01',
        'account_id' => $account->id,
    ]);
    RecurringProjection::create([
        'rule_id' => $rule->id,
        'due_on' => '2026-04-10',
        'issued_on' => '2026-04-01',
        'amount' => -2000, 'currency' => 'USD', 'status' => 'overdue',
        'autopay' => false,
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Overdue rent')
        ->assertSee(__('Mark paid'));

    CarbonImmutable::setTestNow();
});

it('counts unreconciled + projections + transfers', function () {
    CarbonImmutable::setTestNow('2026-04-20');
    $account = setupForWorkbench();

    // Unreconciled import row.
    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-04-15',
        'amount' => -10, 'currency' => 'USD', 'status' => 'cleared',
        'description' => 'Imported row', 'import_source' => 'statement:csv',
    ]);

    // Reconciled row — should NOT count.
    Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-04-10',
        'amount' => -20, 'currency' => 'USD', 'status' => 'cleared',
        'description' => 'Already confirmed', 'reconciled_at' => now(),
    ]);

    $c = Livewire::test('reconciliation-workbench');
    $counts = $c->get('counts');

    expect($counts['unreconciled'])->toBe(1)
        ->and($counts)->not->toHaveKey('pending')
        ->and($counts)->not->toHaveKey('uncategorised');

    CarbonImmutable::setTestNow();
});

// ── Unreconciled queue (primary surface) ──────────────────────────────────

it('shows imported (unreconciled) rows as the primary reconcile surface', function () {
    $account = setupForWorkbench();

    Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -12.34, 'currency' => 'USD',
        'description' => 'Imported from statement',
        'status' => 'cleared',
        'import_source' => 'statement:csv',
        // reconciled_at omitted → null → lands in queue
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Imported from statement')
        ->assertSee(__('Confirm'));
});

it('confirmTransaction stamps reconciled_at and clears the row from the queue', function () {
    $account = setupForWorkbench();

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -5, 'currency' => 'USD',
        'description' => 'Row to confirm', 'status' => 'cleared',
    ]);

    expect($t->reconciled_at)->toBeNull();

    Livewire::test('reconciliation-workbench')
        ->call('confirmTransaction', $t->id);

    expect($t->fresh()->reconciled_at)->not->toBeNull();
});

it('confirmPage flips every visible row without needing a selection', function () {
    $account = setupForWorkbench();

    $ids = [];
    foreach (range(1, 4) as $i) {
        $ids[] = Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => now()->subDays($i)->toDateString(),
            'amount' => -$i, 'currency' => 'USD',
            'description' => "Page row $i", 'status' => 'cleared',
        ])->id;
    }

    Livewire::test('reconciliation-workbench')
        ->assertSet('selected', [])
        ->call('confirmPage');

    foreach ($ids as $id) {
        expect(Transaction::find($id)->reconciled_at)->not->toBeNull();
    }
});

it('bulkConfirm flips every selected row in one update', function () {
    $account = setupForWorkbench();

    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => now()->toDateString(),
            'amount' => -$i, 'currency' => 'USD',
            'description' => "Row $i", 'status' => 'cleared',
        ])->id;
    }

    Livewire::test('reconciliation-workbench')
        ->set('selected', $ids)
        ->call('bulkConfirm');

    foreach ($ids as $id) {
        expect(Transaction::find($id)->reconciled_at)->not->toBeNull();
    }
});

it('editRow loads the transaction into edit state, saveRow persists it', function () {
    $account = setupForWorkbench();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);
    $cat = Category::create(['kind' => 'expense', 'name' => 'Supplies', 'slug' => 'supplies']);

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Old desc', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $t->id)
        ->assertSet('editingId', $t->id)
        ->set('edit.description', 'New desc')
        ->set('edit.counterparty_contact_id', $contact->id)
        ->set('edit.category_id', $cat->id)
        ->set('edit.amount', '-12.34')
        ->call('saveRow');

    $fresh = $t->fresh();
    expect($fresh->description)->toBe('New desc')
        ->and($fresh->counterparty_contact_id)->toBe($contact->id)
        ->and($fresh->category_id)->toBe($cat->id)
        ->and((float) $fresh->amount)->toBe(-12.34)
        ->and($fresh->reconciled_at)->toBeNull(); // editing doesn't confirm
});

it('cancelEditRow clears edit state without writing to the transaction', function () {
    $account = setupForWorkbench();

    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Untouched', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $t->id)
        ->set('edit.description', 'Scratch change')
        ->call('cancelEditRow')
        ->assertSet('editingId', null);

    expect($t->fresh()->description)->toBe('Untouched');
});

it('saveRow appends a new match_pattern to the picked contact and re-resolves siblings', function () {
    $account = setupForWorkbench();
    $contact = Contact::create([
        'kind' => 'org', 'display_name' => 'AcmeCo', 'is_vendor' => true,
        'match_patterns' => 'acmeco',
    ]);

    // Three unreconciled rows — one user edits, two siblings that will
    // get auto-reassigned to the contact via the appended pattern.
    $edited = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Acme*Corp Svc 1', 'status' => 'cleared',
    ]);
    $sib1 = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -11, 'currency' => 'USD',
        'description' => 'Acme*Corp Svc 2', 'status' => 'cleared',
    ]);
    $sib2 = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -12, 'currency' => 'USD',
        'description' => 'Acme*Corp Svc 3', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $edited->id)
        ->set('edit.counterparty_contact_id', $contact->id)
        ->set('edit.match_pattern', 'acme.*corp')
        ->call('saveRow');

    // Pattern is now on the contact.
    expect($contact->fresh()->match_patterns)->toContain('acme.*corp');

    // Both siblings reassigned; edited row itself is also pointing at the contact.
    expect($edited->fresh()->counterparty_contact_id)->toBe($contact->id)
        ->and($sib1->fresh()->counterparty_contact_id)->toBe($contact->id)
        ->and($sib2->fresh()->counterparty_contact_id)->toBe($contact->id);
});

it('sibling re-resolve skips rows whose manually-picked contact name appears in the description', function () {
    $account = setupForWorkbench();

    $acme = Contact::create([
        'kind' => 'org', 'display_name' => 'Acme', 'is_vendor' => true,
        'match_patterns' => 'acme',
    ]);
    $partner = Contact::create(['kind' => 'org', 'display_name' => 'Partner LLC']);

    $edited = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Acme Supplies', 'status' => 'cleared',
    ]);
    // Sibling row where user has already pinned "Partner LLC" and the
    // name literally appears in the description — must NOT be re-resolved.
    $manualSib = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -11, 'currency' => 'USD',
        'description' => 'Partner LLC · Acme referral', 'status' => 'cleared',
        'counterparty_contact_id' => $partner->id,
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $edited->id)
        ->set('edit.counterparty_contact_id', $acme->id)
        ->set('edit.match_pattern', 'acme referral')
        ->call('saveRow');

    expect($edited->fresh()->counterparty_contact_id)->toBe($acme->id)
        ->and($manualSib->fresh()->counterparty_contact_id)->toBe($partner->id);
});

it('saveRow with contact + category propagates category to sibling unreconciled rows of the same contact', function () {
    $account = setupForWorkbench();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);
    $cat = Category::create(['kind' => 'expense', 'name' => 'Office', 'slug' => 'office']);
    $other = Category::create(['kind' => 'expense', 'name' => 'Other', 'slug' => 'other']);

    // Row user is about to edit.
    $edited = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Acme 1', 'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
    ]);
    // Sibling unreconciled, same contact, NO category → should be filled.
    $siblingNull = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -11, 'currency' => 'USD',
        'description' => 'Acme 2', 'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
    ]);
    // Sibling unreconciled, same contact, with an explicit category →
    // must NOT be overwritten.
    $siblingExplicit = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -12, 'currency' => 'USD',
        'description' => 'Acme 3', 'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
        'category_id' => $other->id,
    ]);
    // Already-reconciled row for same contact → must NOT be touched.
    $reconciled = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -13, 'currency' => 'USD',
        'description' => 'Acme 4', 'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
        'reconciled_at' => now(),
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $edited->id)
        ->set('edit.counterparty_contact_id', $contact->id)
        ->set('edit.category_id', $cat->id)
        ->call('saveRow');

    expect($edited->fresh()->category_id)->toBe($cat->id)
        ->and($siblingNull->fresh()->category_id)->toBe($cat->id)
        ->and($siblingExplicit->fresh()->category_id)->toBe($other->id)
        ->and($reconciled->fresh()->category_id)->toBeNull();
});

it('createCounterpartyForRow creates a Contact and sets the edit row override', function () {
    $account = setupForWorkbench();
    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Fresh vendor', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $t->id)
        ->call('createCounterpartyForRow', 'Brand New Vendor', 'edit.counterparty_contact_id');

    $created = Contact::where('display_name', 'Brand New Vendor')->first();
    expect($created)->not->toBeNull()
        ->and((bool) $created->is_vendor)->toBeTrue();
});

it('createCategoryForRow creates a Category and sets the edit row override', function () {
    $account = setupForWorkbench();
    $t = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -10, 'currency' => 'USD',
        'description' => 'Fresh category row', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->call('editRow', $t->id)
        ->call('createCategoryForRow', 'Office snacks', 'edit.category_id');

    $cat = Category::where('name', 'Office snacks')->first();
    expect($cat)->not->toBeNull()
        ->and($cat->kind)->toBe('expense');
});

it('shows the vendor name and spending category alongside the row', function () {
    $account = setupForWorkbench();

    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Costco']);
    $category = Category::create(['kind' => 'expense', 'name' => 'Groceries', 'slug' => 'groceries']);

    Transaction::create([
        'account_id' => $account->id,
        'counterparty_contact_id' => $contact->id,
        'category_id' => $category->id,
        'occurred_on' => now()->toDateString(),
        'amount' => -42, 'currency' => 'USD',
        'description' => 'Costco run', 'status' => 'cleared',
    ]);

    Livewire::test('reconciliation-workbench')
        ->assertSee('Costco')
        ->assertSee('Groceries');
});
