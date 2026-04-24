<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\Decision;
use App\Models\FoodEntry;
use App\Models\JournalEntry;
use App\Models\MediaLogEntry;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('interleaves journal, decision, media, food, and body rows by date', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    JournalEntry::create(['occurred_on' => '2026-04-23', 'title' => 'J1', 'body' => 'hello']);
    Decision::create(['decided_on' => '2026-04-22', 'title' => 'D1']);
    MediaLogEntry::create(['title' => 'M1', 'kind' => 'book', 'finished_on' => '2026-04-21']);
    FoodEntry::create(['eaten_at' => '2026-04-20 08:00:00', 'label' => 'Breakfast']);
    BodyMeasurement::create(['measured_at' => '2026-04-19 07:00:00', 'weight_kg' => 75]);

    $entries = Livewire::test('logs-stream')->get('entries');

    $types = $entries->pluck('type')->all();
    expect($types)->toBe(['journal_entry', 'decision', 'media_log_entry', 'food_entry', 'body_measurement']);
});

it('drops rows outside the active window', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    JournalEntry::create(['occurred_on' => '2026-04-22', 'title' => 'Recent', 'body' => '.']);
    JournalEntry::create(['occurred_on' => '2026-01-01', 'title' => 'Old', 'body' => '.']);

    $entries = Livewire::test('logs-stream')
        ->set('windowDays', 7)
        ->get('entries');

    expect($entries->pluck('label')->all())->toBe(['Recent']);
});

it('composes a body-measurement label from available metrics', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    BodyMeasurement::create(['measured_at' => '2026-04-22 07:00:00', 'weight_kg' => 75, 'body_fat_pct' => 22, 'muscle_pct' => 40]);

    $entries = Livewire::test('logs-stream')->get('entries');
    expect($entries->first()['label'])->toContain('75 kg');
    expect($entries->first()['label'])->toContain('22% fat');
    expect($entries->first()['label'])->toContain('40% muscle');
});

it('falls back to a body snippet when the journal entry has no title', function () {
    CarbonImmutable::setTestNow('2026-04-23 10:00:00');
    authedInHousehold();

    JournalEntry::create([
        'occurred_on' => '2026-04-22',
        'title' => null,
        'body' => 'Long reflection about the day that takes up more than eighty characters so truncation actually kicks in.',
    ]);

    $entries = Livewire::test('logs-stream')->get('entries');
    $label = $entries->first()['label'];
    expect($label)->toContain('Long reflection');
    expect(strlen($label))->toBeLessThanOrEqual(85);
});

it('logs hub defaults to the stream tab', function () {
    authedInHousehold();

    $this->get(route('life.logs'))
        ->assertOk()
        ->assertSeeText('Stream');
});
