<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Contact;
use Livewire\Livewire;

/**
 * Preview-time mirror of the import cascade: contact-default wins over
 * description-rule, which wins over source-label hint. One spec per
 * source so a future regression shows up at the right layer.
 */
it('resolves a row via counterparty contact default category', function () {
    authedInHousehold();
    $groceries = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense']);
    Contact::create(['display_name' => 'Trader Joes', 'category_id' => $groceries->id, 'match_patterns' => 'trader joe']);

    $resolution = resolveRow([
        'occurred_on' => '2026-03-05', 'amount' => -18.75,
        'description' => 'TRADER JOES #123 PORTLAND OR',
        'category_hint' => null,
    ]);

    expect($resolution[0]['category_id'])->toBe($groceries->id)
        ->and($resolution[0]['category_name'])->toBe('Groceries')
        ->and($resolution[0]['source'])->toBe('contact');
});

it('resolves a row via description CategoryRule when the contact has no default', function () {
    authedInHousehold();
    $coffee = Category::create(['name' => 'Coffee', 'slug' => 'coffee', 'kind' => 'expense']);
    CategoryRule::forceCreate([
        'category_id' => $coffee->id, 'pattern_type' => 'contains', 'pattern' => 'starbucks',
        'priority' => 10, 'active' => true,
    ]);

    $resolution = resolveRow([
        'occurred_on' => '2026-03-05', 'amount' => -6.50,
        'description' => 'STARBUCKS #4477 PORTLAND OR',
        'category_hint' => null,
    ]);

    expect($resolution[0]['category_name'])->toBe('Coffee')
        ->and($resolution[0]['source'])->toBe('rule');
});

it('resolves a row via the statement source-category hint when nothing upstream fires', function () {
    authedInHousehold();
    Category::create([
        'name' => 'Shopping', 'slug' => 'shopping', 'kind' => 'expense',
        'match_patterns' => 'Merchandise',
    ]);

    $resolution = resolveRow([
        'occurred_on' => '2026-03-05', 'amount' => -42.00,
        'description' => 'RANDOM STORE WITH NO CONTACT',
        'category_hint' => 'Merchandise',
    ]);

    expect($resolution[0]['category_name'])->toBe('Shopping')
        ->and($resolution[0]['source'])->toBe('hint');
});

it('leaves a row unresolved when no source matches', function () {
    authedInHousehold();

    $resolution = resolveRow([
        'occurred_on' => '2026-03-05', 'amount' => -5.00,
        'description' => 'UNKNOWN VENDOR',
        'category_hint' => 'UnknownCategory',
    ]);

    expect($resolution)->toBe([]);
});

it('contact default wins over rule when both match the same description', function () {
    authedInHousehold();
    Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense']);
    $coffee = Category::create(['name' => 'Coffee', 'slug' => 'coffee', 'kind' => 'expense']);
    $groceries = Category::firstWhere('slug', 'groceries');
    Contact::create(['display_name' => 'Starbucks', 'category_id' => $groceries->id, 'match_patterns' => 'starbucks']);
    CategoryRule::forceCreate([
        'category_id' => $coffee->id, 'pattern_type' => 'contains', 'pattern' => 'starbucks',
        'priority' => 10, 'active' => true,
    ]);

    $resolution = resolveRow([
        'occurred_on' => '2026-03-05', 'amount' => -4.00,
        'description' => 'STARBUCKS #2 PDX',
        'category_hint' => null,
    ]);

    expect($resolution[0]['source'])->toBe('contact')
        ->and($resolution[0]['category_name'])->toBe('Groceries');
});

/**
 * Drive the statements-import component with a single synthetic row
 * and return the resolver's per-row output. Bypasses the upload /
 * parse pipeline entirely — the resolver only reads `$this->parsed`.
 *
 * @param  array<string, mixed>  $row
 * @return array<int, array{category_id: int, category_name: string, source: string}>
 */
function resolveRow(array $row): array
{
    $fileId = 'test-'.uniqid();

    // Minimum state the preview template needs to not blow up during
    // the Livewire::test render. We only care about the resolver's
    // output; the file-card just needs enough keys to draw itself.
    $state = [
        'name' => 'fixture.csv',
        'status' => 'ready',
        'bank_slug' => 'fixture',
        'bank_label' => 'Fixture',
        'import_source' => 'statement:fixture',
        'account_last4' => null,
        'period_start' => null,
        'period_end' => null,
        'opening' => null,
        'closing' => null,
        'rows' => [$row],
        'hash' => null,
        'disk_path' => null,
        'mime' => 'text/csv',
        'size' => 0,
        'detected_year' => null,
    ];

    return Livewire::test('statements-import')
        ->set('parsed', [$fileId => $state])
        ->instance()
        ->categoryResolutionForFile($fileId);
}
