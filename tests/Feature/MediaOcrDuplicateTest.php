<?php

use App\Models\Contact;
use App\Models\Household;
use App\Models\Media;
use App\Models\RecurringRule;
use App\Support\CurrentHousehold;
use Livewire\Livewire;

function mediaWithExtraction(array $overrides = []): Media
{
    return Media::create([
        'disk' => 'local',
        'path' => 'scans/pge.png',
        'original_name' => 'pge.png',
        'mime' => 'image/png',
        'size' => 100,
        'ocr_status' => 'done',
        'ocr_text' => 'PG&E statement',
        'extraction_status' => 'done',
        'ocr_extracted' => array_replace([
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
        ], $overrides),
    ]);
}

it('flags a duplicate when an existing rule matches by title', function () {
    authedInHousehold();
    $m = mediaWithExtraction();
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Pacific Gas & Electric',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1', 'dtstart' => '2026-01-01',
        'active' => true,
    ]);

    $c = Livewire::test('media-index')->call('openPreview', $m->id);
    $dup = $c->get('duplicateRule');
    expect($dup)->not->toBeNull()
        ->and($dup->title)->toBe('Pacific Gas & Electric');
    $c->assertSee('Record payment')
        ->assertSee('Create new bill anyway')
        ->assertDontSeeHtml('>Create bill<');
});

it('matches case-insensitively and trims whitespace', function () {
    authedInHousehold();
    $m = mediaWithExtraction(['vendor' => '  PACIFIC GAS & ELECTRIC  ']);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Pacific Gas & Electric',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1', 'dtstart' => '2026-01-01',
        'active' => true,
    ]);

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('duplicateRule.title', 'Pacific Gas & Electric');
});

it('matches via counterparty contact when the rule title differs', function () {
    authedInHousehold();
    $m = mediaWithExtraction();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Pacific Gas & Electric']);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Electric + Gas (utilities)',
        'counterparty_contact_id' => $contact->id,
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1', 'dtstart' => '2026-01-01',
        'active' => true,
    ]);

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('duplicateRule.title', 'Electric + Gas (utilities)');
});

it('shows no warning when no rule matches', function () {
    authedInHousehold();
    $m = mediaWithExtraction();
    // A different vendor rule exists, but doesn't match.
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Spotify',
        'amount' => -11.99, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-01-01',
        'active' => true,
    ]);

    $c = Livewire::test('media-index')->call('openPreview', $m->id);
    expect($c->get('duplicateRule'))->toBeNull();
    $c->assertSee('Create bill')
        ->assertSee('Create transaction')
        ->assertDontSee('Record payment');
});

it('ignores inactive rules', function () {
    authedInHousehold();
    $m = mediaWithExtraction();
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Pacific Gas & Electric',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-01-01',
        'active' => false,
    ]);

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('duplicateRule', null);
});

it('does not match rules in another household', function () {
    authedInHousehold();
    $m = mediaWithExtraction();

    $other = Household::create(['name' => 'Neighbor', 'default_currency' => 'USD']);
    CurrentHousehold::set($other);
    RecurringRule::create([
        'kind' => 'bill', 'title' => 'Pacific Gas & Electric',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-01-01',
        'active' => true,
    ]);
    // Swap back so the test user is authed in the original household.
    CurrentHousehold::set(Household::where('name', 'Test')->first());

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('duplicateRule', null);
});

it('returns null when the media has no extracted payload', function () {
    authedInHousehold();
    $m = Media::create([
        'disk' => 'local', 'path' => 'b.png', 'original_name' => 'b.png',
        'mime' => 'image/png', 'size' => 1,
        'ocr_status' => 'done', 'ocr_text' => 'some text',
    ]);

    Livewire::test('media-index')
        ->call('openPreview', $m->id)
        ->assertSet('duplicateRule', null);
});
