<?php

use App\Models\Account;
use App\Models\BudgetCap;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use Livewire\Livewire;

function pageCat(string $name): Category
{
    return Category::create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'kind' => 'expense',
    ]);
}

// ─── /budgets ─────────────────────────────────────────────────────────────

it('budgets page renders with empty state and a New envelope button', function () {
    authedInHousehold();
    $this->get(route('fiscal.budgets'))
        ->assertOk()
        ->assertSee(__('No envelopes yet.'))
        ->assertSee(__('New envelope'));
});

it('budgets page lists existing envelopes with inspector dispatch', function () {
    authedInHousehold();
    $cat = pageCat('Groceries');
    $cap = BudgetCap::forceCreate(['category_id' => $cat->id, 'monthly_cap' => 500, 'currency' => 'USD', 'active' => true]);

    $response = $this->get(route('fiscal.budgets'))->assertOk();
    $response->assertSeeText('Groceries');
    // Livewire serializes wire:click via HTML-encoded quotes. Look for the
    // cap id embedded in a dispatch call on the rendered row.
    expect($response->getContent())->toMatch("/inspector-open.+budget_cap.+id:\\s*{$cap->id}/");
});

it('creates a budget envelope via the inspector', function () {
    authedInHousehold();
    $cat = pageCat('Dining');

    Livewire::test('inspector')
        ->call('openInspector', 'budget_cap')
        ->set('budget_category_id', $cat->id)
        ->set('budget_monthly_cap', '300')
        ->set('budget_currency', 'USD')
        ->set('budget_active', true)
        ->call('save');

    expect(BudgetCap::where('category_id', $cat->id)->count())->toBe(1);
});

it('edits an envelope via the inspector', function () {
    authedInHousehold();
    $cat = pageCat('Utilities');
    $cap = BudgetCap::forceCreate(['category_id' => $cat->id, 'monthly_cap' => 100, 'currency' => 'USD', 'active' => true]);

    Livewire::test('inspector')
        ->call('openInspector', 'budget_cap', $cap->id)
        ->assertSet('budget_monthly_cap', '100.0000')
        ->set('budget_monthly_cap', '250')
        ->call('save');

    expect((float) $cap->fresh()->monthly_cap)->toBe(250.0);
});

// ─── /category-rules ──────────────────────────────────────────────────────

it('category-rules page renders with empty state', function () {
    authedInHousehold();
    $this->get(route('fiscal.category_rules'))
        ->assertOk()
        ->assertSee(__('No rules yet.'));
});

it('creates a rule via the inspector', function () {
    authedInHousehold();
    $cat = pageCat('Coffee');

    Livewire::test('inspector')
        ->call('openInspector', 'category_rule')
        ->set('rule_category_id', $cat->id)
        ->set('rule_pattern_type', 'contains')
        ->set('rule_pattern', 'starbucks')
        ->set('rule_priority', 50)
        ->set('rule_active', true)
        ->call('save');

    expect(CategoryRule::where('category_id', $cat->id)->count())->toBe(1);
});

it('applyToHistory re-categorizes existing uncategorized transactions', function () {
    authedInHousehold();
    $cat = pageCat('Utilities');
    $acc = Account::create(['name' => 'C', 'type' => 'checking', 'currency' => 'USD']);
    Transaction::create([
        'account_id' => $acc->id, 'amount' => -90, 'currency' => 'USD',
        'occurred_on' => now(), 'description' => 'PGE POWER', 'status' => 'cleared',
    ]);
    CategoryRule::forceCreate(['category_id' => $cat->id, 'pattern_type' => 'contains', 'pattern' => 'PGE', 'active' => true]);

    Livewire::test('category-rules-index')->call('applyToHistory');

    expect(Transaction::first()->category_id)->toBe($cat->id);
});
