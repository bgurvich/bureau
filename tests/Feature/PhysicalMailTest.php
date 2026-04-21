<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\PhysicalMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('lists physical mail rows with kind + received_on filters', function () {
    authedInHousehold();

    $bill = PhysicalMail::create([
        'received_on' => '2026-04-10',
        'kind' => 'bill',
        'subject' => 'Electric bill',
    ]);
    $junk = PhysicalMail::create([
        'received_on' => '2026-04-11',
        'kind' => 'ad',
        'subject' => 'Supermarket flyer',
    ]);

    $component = Livewire::test('physical-mail-index');
    expect($component->instance()->mail->pluck('id')->all())
        ->toEqualCanonicalizing([$bill->id, $junk->id]);

    $component->set('kindFilter', 'bill');
    expect($component->instance()->mail->pluck('id')->all())->toBe([$bill->id]);
});

it('hides processed rows when the status filter is set to unprocessed', function () {
    authedInHousehold();

    $open = PhysicalMail::create([
        'received_on' => '2026-04-10',
        'kind' => 'letter',
        'subject' => 'Still reading this',
    ]);
    PhysicalMail::create([
        'received_on' => '2026-04-05',
        'kind' => 'letter',
        'subject' => 'Handled',
        'processed_at' => now(),
    ]);

    $component = Livewire::test('physical-mail-index')->set('statusFilter', 'unprocessed');
    expect($component->instance()->mail->pluck('id')->all())->toBe([$open->id]);
});

it('creates a physical_mail record from the inspector', function () {
    authedInHousehold();

    Livewire::test('inspector')
        ->call('openInspector', 'physical_mail')
        ->set('pm_kind', 'medical')
        ->set('pm_received_on', '2026-04-15')
        ->set('title', 'Doctor follow-up')
        ->set('description', 'Labs enclosed, call back by Friday.')
        ->set('pm_action_required', true)
        ->call('save');

    $row = PhysicalMail::where('subject', 'Doctor follow-up')->first();
    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe('medical')
        ->and($row->received_on->toDateString())->toBe('2026-04-15')
        ->and($row->action_required)->toBeTrue()
        ->and($row->summary)->toBe('Labs enclosed, call back by Friday.');
});

it('attaches a photo and creates a stub physical_mail row from mobile capture', function () {
    authedInHousehold();
    Queue::fake();

    $file = UploadedFile::fake()->image('envelope.jpg', 800, 600)->size(50);

    $component = Livewire::test('mobile.capture-post')
        ->set('photo', $file)
        ->call('save', false);

    $mail = PhysicalMail::first();
    expect($mail)->not->toBeNull()
        ->and($mail->kind)->toBe('other')
        ->and($mail->media()->count())->toBe(1)
        ->and(Media::first()->source)->toBe('mobile');

    $component->assertRedirect(route('mobile.capture'));
});

it('resolves the /records hub Post tab and the standalone /post URL', function () {
    authedInHousehold();

    $this->get(route('records.index', ['tab' => 'post']))->assertOk();
    $this->get(route('records.post'))->assertOk();
});
