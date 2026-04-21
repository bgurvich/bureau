<?php

use App\Jobs\ExtractOcrStructure;
use App\Models\Account;
use App\Models\MailAttachment;
use App\Models\MailIngestInbox;
use App\Models\MailMessage;
use App\Models\Media;
use App\Models\RecurringRule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config()->set('services.lm_studio.enabled', false);
});

function mailWithScan(): array
{
    authedInHousehold();
    $inbox = MailIngestInbox::create(['local_address' => 'bills@bureau.app', 'active' => true]);
    $mail = MailMessage::create([
        'inbox_id' => $inbox->id,
        'received_at' => now(),
        'from_address' => 'billing@pge.com',
        'subject' => 'April statement',
        'message_id' => '<a@b>',
        'provider_message_id' => 'p-1',
    ]);
    $media = Media::create([
        'disk' => 'local', 'source' => 'mail',
        'path' => 'scans/m.png', 'original_name' => 'm.png',
        'mime' => 'image/png', 'size' => 100,
        'ocr_status' => 'done', 'ocr_text' => 'x',
        'extraction_status' => 'done',
        'ocr_extracted' => ['vendor' => 'PG&E', 'amount' => 86.64, 'issued_on' => '2026-04-17'],
    ]);
    MailAttachment::create([
        'message_id' => $mail->id, 'media_id' => $media->id,
        'filename' => 'm.png', 'mime' => 'image/png', 'size' => 100,
    ]);

    return [$mail, $media];
}

it('MailMessage::processedRecords derives a created Bill from the attached scan', function () {
    [$mail, $media] = mailWithScan();
    $rule = RecurringRule::create([
        'kind' => 'bill', 'title' => 'PG&E',
        'amount' => -86.64, 'currency' => 'USD',
        'rrule' => 'FREQ=MONTHLY', 'dtstart' => '2026-04-17',
    ]);
    $rule->media()->attach($media->id, ['role' => 'receipt']);

    $records = $mail->fresh()->processedRecords();
    expect($records)->toHaveCount(1)
        ->and($records->first())->toBeInstanceOf(RecurringRule::class)
        ->and($records->first()->id)->toBe($rule->id);
});

it('Inspector auto-mark cascades processed_at to the MailMessage', function () {
    [$mail, $media] = mailWithScan();
    $account = Account::create(['type' => 'checking', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);

    Livewire::test('inspector')
        ->call('openInspector', 'bill', null, $media->id)
        ->set('account_id', $account->id)
        ->call('save');

    expect($media->fresh()->processed_at)->not->toBeNull()
        ->and($mail->fresh()->processed_at)->not->toBeNull();
});

it('Mail with multiple attachments only flips when ALL are processed', function () {
    authedInHousehold();
    $inbox = MailIngestInbox::create(['local_address' => 'q@bureau.app', 'active' => true]);
    $mail = MailMessage::create([
        'inbox_id' => $inbox->id, 'received_at' => now(),
        'subject' => 's', 'message_id' => '<m@z>', 'provider_message_id' => 'pq',
    ]);
    $m1 = Media::create(['disk' => 'local', 'path' => 'a.png', 'original_name' => 'a', 'mime' => 'image/png', 'size' => 1]);
    $m2 = Media::create(['disk' => 'local', 'path' => 'b.png', 'original_name' => 'b', 'mime' => 'image/png', 'size' => 1]);
    MailAttachment::create(['message_id' => $mail->id, 'media_id' => $m1->id, 'filename' => 'a', 'mime' => 'image/png', 'size' => 1]);
    MailAttachment::create(['message_id' => $mail->id, 'media_id' => $m2->id, 'filename' => 'b', 'mime' => 'image/png', 'size' => 1]);

    // Process only one
    $m1->forceFill(['processed_at' => now()])->save();
    MailMessage::cascadeProcessedFromMedia($m1->id);
    expect($mail->fresh()->processed_at)->toBeNull();

    // Process the second — now mail should flip
    $m2->forceFill(['processed_at' => now()])->save();
    MailMessage::cascadeProcessedFromMedia($m2->id);
    expect($mail->fresh()->processed_at)->not->toBeNull();
});

it('HTML-only inbound email synthesizes a Media from the body and runs extraction', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'bills+u@bureau.app', 'active' => true]);
    config()->set('services.lm_studio.enabled', true);
    Bus::fake();

    $this->postJson('/webhooks/postmark/inbound', [
        'From' => 'billing@att.com', 'FromName' => 'AT&T',
        'ToFull' => [['Email' => 'bills+u@bureau.app']],
        'Subject' => 'Your AT&T bill',
        'MessageID' => 'pm-html',
        'Headers' => [['Name' => 'Message-ID', 'Value' => '<html@att>']],
        'Date' => 'Thu, 17 Apr 2026 10:30:00 +0000',
        'TextBody' => '',
        'HtmlBody' => '<html><body><h1>AT&amp;T</h1><p>Your statement total is <strong>$129.99</strong> due by <em>2026-05-10</em>.</p>'
                     .'<p>Thank you for your business. This message spans enough characters to pass the length floor for body synthesis — it needs at least 100 chars of meaningful text.</p></body></html>',
        'Attachments' => [],
    ])->assertOk();

    $media = Media::firstOrFail();
    expect($media->source)->toBe('mail')
        ->and($media->mime)->toBe('text/html')
        ->and($media->ocr_status)->toBe('done')
        ->and($media->ocr_text)->toContain('AT&T')
        ->and($media->ocr_text)->toContain('$129.99')
        ->and($media->extraction_status)->toBe('pending');

    Bus::assertDispatched(ExtractOcrStructure::class, fn ($j) => $j->mediaId === $media->id);
});

it('short-body emails do not spawn a body Media', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'short@bureau.app', 'active' => true]);

    $this->postJson('/webhooks/postmark/inbound', [
        'From' => 'a@x.com',
        'ToFull' => [['Email' => 'short@bureau.app']],
        'Subject' => 'ping',
        'MessageID' => 'pm-short',
        'Headers' => [['Name' => 'Message-ID', 'Value' => '<short@x>']],
        'Date' => 'Thu, 17 Apr 2026 10:30:00 +0000',
        'TextBody' => 'Short.',
        'HtmlBody' => '',
        'Attachments' => [],
    ])->assertOk();

    expect(Media::count())->toBe(0);
});

it('emails with image attachments do NOT also spawn a body Media', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'mixed@bureau.app', 'active' => true]);

    $this->postJson('/webhooks/postmark/inbound', [
        'From' => 'a@x.com',
        'ToFull' => [['Email' => 'mixed@bureau.app']],
        'Subject' => 'Statement',
        'MessageID' => 'pm-mix',
        'Headers' => [['Name' => 'Message-ID', 'Value' => '<mix@x>']],
        'Date' => 'Thu, 17 Apr 2026 10:30:00 +0000',
        'TextBody' => 'See attached. This text is long enough to trip the 100-char floor, which means if we were going to synthesize a body-Media we would, but we should not because an image attachment already exists for OCR.',
        'HtmlBody' => '',
        'Attachments' => [[
            'Name' => 's.png', 'Content' => base64_encode('fake'), 'ContentType' => 'image/png', 'ContentLength' => 4,
        ]],
    ])->assertOk();

    expect(Media::count())->toBe(1)
        ->and(Media::first()->mime)->toBe('image/png');
});
