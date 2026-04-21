<?php

use App\Jobs\OcrMedia;
use App\Models\Integration;
use App\Models\MailAttachment;
use App\Models\MailIngestInbox;
use App\Models\MailMessage;
use App\Models\Media;
use App\Support\Mail\GmailProvider;
use App\Support\Mail\ImapProvider;
use App\Support\Mail\JmapProvider;
use App\Support\Mail\MailAttachmentData;
use App\Support\Mail\MailIngester;
use App\Support\Mail\MailMessageData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function postmarkPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'From' => 'billing@pge.com',
        'FromName' => 'Pacific Gas & Electric',
        'ToFull' => [['Email' => 'bills+u1@inbound.bureau.app']],
        'OriginalRecipient' => 'bills+u1@inbound.bureau.app',
        'Subject' => 'Your April statement',
        'MessageID' => 'postmark-abc',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<pge-april@pge.com>'],
        ],
        'Date' => 'Thu, 17 Apr 2026 10:30:00 +0000',
        'TextBody' => 'Your April statement is $86.64',
        'HtmlBody' => '<p>Your April statement is $86.64</p>',
        'Attachments' => [],
    ], $overrides);
}

beforeEach(function () {
    config()->set('services.postmark.webhook_user', '');
    config()->set('services.postmark.webhook_password', '');
    Storage::fake('local');
});

it('Postmark webhook creates a MailMessage routed by To address', function () {
    authedInHousehold();
    Bus::fake();
    MailIngestInbox::create([
        'local_address' => 'bills+u1@inbound.bureau.app',
        'active' => true,
    ]);

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload())
        ->assertOk()
        ->assertJson(['ok' => true, 'created' => true]);

    $m = MailMessage::first();
    expect($m)->not->toBeNull()
        ->and($m->from_address)->toBe('billing@pge.com')
        ->and($m->subject)->toBe('Your April statement')
        ->and($m->message_id)->toBe('<pge-april@pge.com>');
});

it('Postmark webhook requires basic auth when configured', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'x@bureau.app', 'active' => true]);
    config()->set('services.postmark.webhook_user', 'hook');
    config()->set('services.postmark.webhook_password', 's3cret');

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload([
        'ToFull' => [['Email' => 'x@bureau.app']],
    ]))->assertStatus(401);

    $this->withBasicAuth('hook', 's3cret')
        ->postJson('/webhooks/postmark/inbound', postmarkPayload([
            'ToFull' => [['Email' => 'x@bureau.app']],
        ]))
        ->assertOk();
});

it('Postmark webhook 404s for unknown To address', function () {
    authedInHousehold();
    // No MailIngestInbox matches the payload's To address.

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload())
        ->assertStatus(404);

    expect(MailMessage::count())->toBe(0);
});

it('Postmark webhook saves attachments as Media and queues OCR', function () {
    authedInHousehold();
    Bus::fake();
    MailIngestInbox::create(['local_address' => 'bills+u1@inbound.bureau.app', 'active' => true]);

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload([
        'Attachments' => [[
            'Name' => 'statement.png',
            'Content' => base64_encode('fake-png-bytes'),
            'ContentType' => 'image/png',
            'ContentLength' => 14,
        ]],
    ]))->assertOk();

    $m = MailMessage::firstOrFail();
    $att = MailAttachment::firstOrFail();
    expect($att->message_id)->toBe($m->id)
        ->and($att->filename)->toBe('statement.png')
        ->and($att->media_id)->not->toBeNull();

    $media = Media::firstOrFail();
    expect($media->mime)->toBe('image/png')
        ->and($media->ocr_status)->toBe('pending');

    Bus::assertDispatched(OcrMedia::class, fn ($job) => $job->mediaId === $media->id);
});

it('deduplicates when the same email arrives via two different inboxes', function () {
    authedInHousehold();
    MailIngestInbox::create(['local_address' => 'bills+a@inbound.bureau.app', 'active' => true]);
    MailIngestInbox::create(['local_address' => 'bills+b@inbound.bureau.app', 'active' => true]);

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload([
        'ToFull' => [['Email' => 'bills+a@inbound.bureau.app']],
    ]))->assertOk();

    $this->postJson('/webhooks/postmark/inbound', postmarkPayload([
        'ToFull' => [['Email' => 'bills+b@inbound.bureau.app']],
    ]))->assertOk()->assertJson(['created' => false]);

    expect(MailMessage::count())->toBe(1);
});

it('JmapProvider maps an Email/get response into MailMessageData', function () {
    authedInHousehold();
    $integration = Integration::create([
        'provider' => 'jmap_fastmail', 'kind' => 'mail', 'label' => 'Fastmail',
        'credentials' => ['token' => 'FM-TOKEN', 'session_url' => 'https://fm.test/.well-known/jmap'],
        'settings' => ['account_id' => 'acct-1', 'folder_id' => 'mb-1', 'last_synced_at' => null],
    ]);

    Http::fake([
        'fm.test/.well-known/jmap' => Http::response([
            'apiUrl' => 'https://fm.test/api',
            'downloadUrl' => 'https://fm.test/download/{accountId}/{blobId}/{name}',
        ]),
        'fm.test/api' => Http::response([
            'methodResponses' => [
                ['Email/query', ['ids' => ['e1']], 'c1'],
                ['Email/get', ['list' => [[
                    'id' => 'e1',
                    'blobId' => 'b1',
                    'messageId' => ['<rfc-1@fm>'],
                    'receivedAt' => '2026-04-17T10:30:00Z',
                    'from' => [['email' => 'a@x.com', 'name' => 'A']],
                    'to' => [['email' => 'me@fm.com']],
                    'subject' => 'Hi',
                    'textBody' => [['partId' => 'p1']],
                    'htmlBody' => [['partId' => 'p2']],
                    'bodyValues' => [
                        'p1' => ['value' => 'plain body'],
                        'p2' => ['value' => '<p>html</p>'],
                    ],
                    'attachments' => [[
                        'blobId' => 'att-1', 'size' => 10, 'name' => 'receipt.png', 'type' => 'image/png',
                    ]],
                    'headers' => [],
                ]]], 'c2'],
            ],
        ]),
    ]);

    $messages = iterator_to_array((new JmapProvider)->pullSince($integration));
    expect($messages)->toHaveCount(1);
    $m = $messages[0];
    expect($m->messageId)->toBe('<rfc-1@fm>')
        ->and($m->fromAddress)->toBe('a@x.com')
        ->and($m->subject)->toBe('Hi')
        ->and($m->textBody)->toBe('plain body')
        ->and($m->attachments[0]->filename)->toBe('receipt.png')
        ->and($m->attachments[0]->providerRef)->toBe('https://fm.test/download/acct-1/att-1/receipt.png');
});

it('GmailProvider refreshes tokens and maps messages.get payload', function () {
    authedInHousehold();
    config()->set('services.google.client_id', 'cid');
    config()->set('services.google.client_secret', 'csec');

    $integration = Integration::create([
        'provider' => 'gmail', 'kind' => 'mail', 'label' => 'user@gmail.com',
        'credentials' => ['refresh_token' => 'r-1'],
        'settings' => ['label_ids' => [], 'history_id' => ''],
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
        'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages/m1?*' => Http::response([
            'id' => 'm1',
            'internalDate' => (string) (CarbonImmutable::parse('2026-04-17 10:30:00')->timestamp * 1000),
            'payload' => [
                'headers' => [
                    ['name' => 'From', 'value' => 'Billing <bill@x.com>'],
                    ['name' => 'To', 'value' => 'me@gmail.com'],
                    ['name' => 'Subject', 'value' => 'Test'],
                    ['name' => 'Message-ID', 'value' => '<abc@x>'],
                ],
                'mimeType' => 'multipart/mixed',
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => strtr(base64_encode('hello world'), '+/', '-_')]],
                    ['mimeType' => 'image/png', 'filename' => 'r.png',
                        'body' => ['attachmentId' => 'att-1', 'size' => 5]],
                ],
            ],
        ]),
        'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['historyId' => '99']),
    ]);

    $messages = iterator_to_array((new GmailProvider)->pullSince($integration));
    expect($messages)->toHaveCount(1);
    $m = $messages[0];
    expect($m->messageId)->toBe('abc@x')
        ->and($m->fromAddress)->toBe('bill@x.com')
        ->and($m->subject)->toBe('Test')
        ->and($m->textBody)->toBe('hello world')
        ->and($m->attachments)->toHaveCount(1)
        ->and($m->attachments[0]->filename)->toBe('r.png');

    // cursor advanced
    expect($integration->fresh()->settings['history_id'])->toBe('99');
});

it('ImapProvider throws on use', function () {
    $integration = new Integration(['provider' => 'imap']);
    expect(fn () => iterator_to_array((new ImapProvider)->pullSince($integration)))
        ->toThrow(RuntimeException::class);
});

it('MailIngester dedups on message_id across integrations', function () {
    $household = authedInHousehold()->defaultHousehold;
    $ingester = app(MailIngester::class);

    $data = new MailMessageData(
        messageId: '<same@x>',
        providerMessageId: 'p1',
        receivedAt: CarbonImmutable::now(),
        fromAddress: 'a@x.com',
        fromName: null,
        toAddresses: ['me@bureau.app'],
        subject: 'S',
        textBody: 'body',
        htmlBody: null,
    );

    $ingester->ingest($household, $data);
    $ingester->ingest($household, clone $data);
    expect(MailMessage::count())->toBe(1);
});

it('MailIngester persists unreachable attachments with a null media_id', function () {
    $household = authedInHousehold()->defaultHousehold;
    $ingester = app(MailIngester::class);

    $data = new MailMessageData(
        messageId: '<noatt@x>',
        providerMessageId: 'p2',
        receivedAt: CarbonImmutable::now(),
        fromAddress: 'a@x.com',
        fromName: null,
        toAddresses: [],
        subject: null,
        textBody: null,
        htmlBody: null,
        attachments: [new MailAttachmentData(
            filename: 'lost.bin', mime: null, size: null, content: null, providerRef: null,
        )],
    );
    $ingester->ingest($household, $data);

    $att = MailAttachment::firstOrFail();
    expect($att->media_id)->toBeNull()
        ->and($att->filename)->toBe('lost.bin');
});

it('mail:sync walks active integrations and reports summary', function () {
    authedInHousehold();
    Integration::create([
        'provider' => 'imap', 'kind' => 'mail', 'label' => 'legacy',
        'credentials' => [], 'settings' => [], 'status' => 'active',
    ]);

    $this->artisan('mail:sync')
        ->expectsOutputToContain('failed: IMAP provider is not implemented yet.')
        ->assertExitCode(0);
});
