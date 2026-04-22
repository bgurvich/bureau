<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Support\Birthdays;
use App\Support\ContactsCsvImporter;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('nextAnniversary returns this year when the birthday is still upcoming', function () {
    $today = CarbonImmutable::parse('2026-04-10');
    $birthday = CarbonImmutable::parse('1985-05-20');

    $next = Birthdays::nextAnniversary($birthday, $today);
    expect($next->toDateString())->toBe('2026-05-20');
});

it('nextAnniversary rolls over to next year when this year has passed', function () {
    $today = CarbonImmutable::parse('2026-06-01');
    $birthday = CarbonImmutable::parse('1985-05-20');

    $next = Birthdays::nextAnniversary($birthday, $today);
    expect($next->toDateString())->toBe('2027-05-20');
});

it('nextAnniversary returns today when the birthday is today', function () {
    $today = CarbonImmutable::parse('2026-05-20');
    $birthday = CarbonImmutable::parse('1985-05-20');

    $next = Birthdays::nextAnniversary($birthday, $today);
    expect($next->toDateString())->toBe('2026-05-20');
});

it('ageOn returns null when the stored year is the 1900 sentinel', function () {
    expect(Birthdays::ageOn(CarbonImmutable::parse('1900-05-20'), CarbonImmutable::parse('2026-05-20')))->toBeNull();
});

it('ageOn returns the age when the year is real', function () {
    expect(Birthdays::ageOn(CarbonImmutable::parse('1985-05-20'), CarbonImmutable::parse('2026-05-20')))->toBe(41);
});

it('upcoming() returns only contacts whose anniversary falls inside the window, sorted', function () {
    authedInHousehold();

    // Today: 2026-04-22.
    CarbonImmutable::setTestNow('2026-04-22');

    // Within 30d
    Contact::create(['kind' => 'person', 'display_name' => 'May 10', 'birthday' => '1985-05-10']);
    // Within 30d, earlier in the window → must sort first
    Contact::create(['kind' => 'person', 'display_name' => 'Apr 28', 'birthday' => '1990-04-28']);
    // Outside 30d
    Contact::create(['kind' => 'person', 'display_name' => 'Jun 30', 'birthday' => '1980-06-30']);
    // No birthday
    Contact::create(['kind' => 'person', 'display_name' => 'Unknown']);

    $names = Birthdays::upcoming(30)->pluck('display_name')->all();
    expect($names)->toBe(['Apr 28', 'May 10']);

    CarbonImmutable::setTestNow();
});

it('saves a contact with a known-year birthday via the inspector', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'person', 'display_name' => 'Aunt Sue']);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'contact', id: $contact->id)
        ->set('birthday', '1985-05-10')
        ->set('birthday_year_known', true)
        ->call('save');

    expect($contact->fresh()->birthday?->toDateString())->toBe('1985-05-10');
});

it('normalises a birthday to 1900-MM-DD when year is unknown', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'person', 'display_name' => 'Aunt Sue']);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'contact', id: $contact->id)
        ->set('birthday', '2026-05-10') // user types any year with the right MM-DD
        ->set('birthday_year_known', false)
        ->call('save');

    // Year got rewritten to 1900 sentinel; month/day preserved.
    expect($contact->fresh()->birthday?->toDateString())->toBe('1900-05-10');
});

it('clearing the birthday input writes null back', function () {
    authedInHousehold();
    $contact = Contact::create([
        'kind' => 'person', 'display_name' => 'Aunt Sue', 'birthday' => '1985-05-10',
    ]);

    Livewire::test('inspector')
        ->dispatch('inspector-open', type: 'contact', id: $contact->id)
        ->set('birthday', '')
        ->call('save');

    expect($contact->fresh()->birthday)->toBeNull();
});

it('CSV round-trip preserves the 1900 sentinel for year-unknown birthdays', function () {
    authedInHousehold();

    // Pre-existing contact w/ year-unknown birthday; merge path must
    // keep sentinel intact even after an import.
    $existing = Contact::create([
        'kind' => 'person', 'display_name' => 'Aunt Sue', 'birthday' => '1900-05-10',
    ]);

    // Import a CSV that specifies a different birthday — merge path
    // leaves the existing non-empty birthday untouched.
    $csv = "display_name,birthday\n".'"Aunt Sue",2000-05-10'."\n";
    ContactsCsvImporter::import($csv);

    expect($existing->fresh()->birthday?->toDateString())->toBe('1900-05-10');
});
