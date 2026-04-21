<?php

use App\Models\Account;
use App\Models\Integration;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\Media;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders the empty state when no mail is ingested', function () {
    authedInHousehold();

    $this->get('/mail')
        ->assertOk()
        ->assertSee('No mail ingested yet');
});

it('lists mail messages newest-first', function () {
    authedInHousehold();
    MailMessage::create([
        'received_at' => CarbonImmutable::parse('2026-04-10 09:00'),
        'from_address' => 'a@x.com', 'subject' => 'Older',
        'message_id' => '<older@x>', 'provider_message_id' => 'a1',
    ]);
    MailMessage::create([
        'received_at' => CarbonImmutable::parse('2026-04-18 10:00'),
        'from_address' => 'b@x.com', 'subject' => 'Newer',
        'message_id' => '<newer@x>', 'provider_message_id' => 'a2',
    ]);

    $response = $this->get('/mail')->assertOk();
    $body = $response->getContent();
    expect(strpos($body, 'Newer'))->toBeLessThan(strpos($body, 'Older'));
});

it('filters by from/subject search', function () {
    authedInHousehold();
    MailMessage::create([
        'received_at' => now(), 'from_address' => 'billing@pge.com',
        'subject' => 'April statement', 'message_id' => '<1@x>', 'provider_message_id' => 'b1',
    ]);
    MailMessage::create([
        'received_at' => now(), 'from_address' => 'newsletter@ex.com',
        'subject' => 'Weekly digest', 'message_id' => '<2@x>', 'provider_message_id' => 'b2',
    ]);

    Livewire::test('mail-index')
        ->set('search', 'pge')
        ->assertSee('April statement')
        ->assertDontSee('Weekly digest');
});

it('filters by source', function () {
    authedInHousehold();
    $gmail = Integration::create([
        'provider' => 'gmail', 'kind' => 'mail', 'label' => 'g@x', 'status' => 'active',
    ]);
    MailMessage::create([
        'received_at' => now(), 'integration_id' => $gmail->id,
        'from_address' => 'a@x', 'subject' => 'From Gmail',
        'message_id' => '<g@x>', 'provider_message_id' => 'g1',
    ]);
    MailMessage::create([
        'received_at' => now(), 'from_address' => 'b@x', 'subject' => 'From Postmark',
        'message_id' => '<p@x>', 'provider_message_id' => 'p1',
    ]);

    Livewire::test('mail-index')
        ->set('sourceFilter', 'gmail')
        ->assertSee('From Gmail')
        ->assertDontSee('From Postmark');
});

it('renders a thumbnail when the message has an image attachment', function () {
    authedInHousehold();
    $msg = MailMessage::create([
        'received_at' => now(), 'from_address' => 'a@x', 'subject' => 'Receipt',
        'message_id' => '<att@x>', 'provider_message_id' => 'att',
    ]);
    $media = Media::create([
        'disk' => 'local', 'path' => 's.png', 'original_name' => 's.png',
        'mime' => 'image/png', 'size' => 100,
    ]);
    MailAttachment::create([
        'message_id' => $msg->id, 'media_id' => $media->id,
        'filename' => 's.png', 'mime' => 'image/png', 'size' => 100,
    ]);

    $this->get('/mail')
        ->assertOk()
        ->assertSeeHtml('src="'.route('media.file', $media).'"');
});

it('shows Create bill + Create transaction shortcuts for messages with unlinked attachments', function () {
    authedInHousehold();
    $msg = MailMessage::create([
        'received_at' => now(), 'from_address' => 'billing@pge.com', 'subject' => 'April bill',
        'message_id' => '<pdf@x>', 'provider_message_id' => 'pdf',
    ]);
    $media = Media::create([
        'disk' => 'local', 'path' => 'bill.pdf', 'original_name' => 'bill.pdf',
        'mime' => 'application/pdf', 'size' => 5000,
    ]);
    MailAttachment::create([
        'message_id' => $msg->id, 'media_id' => $media->id,
        'filename' => 'bill.pdf', 'mime' => 'application/pdf', 'size' => 5000,
    ]);

    $this->get('/mail')
        ->assertOk()
        ->assertSee('Create bill')
        ->assertSee('Create transaction')
        ->assertSeeHtml('type: \'bill\', mediaId: '.$media->id)
        ->assertSeeHtml('type: \'transaction\', mediaId: '.$media->id);
});

it('hides the Create shortcuts when a message already has linked records', function () {
    $user = authedInHousehold();
    $msg = MailMessage::create([
        'received_at' => now(), 'from_address' => 'x@y', 'subject' => 'Already processed',
        'message_id' => '<done@x>', 'provider_message_id' => 'done',
    ]);
    $media = Media::create([
        'disk' => 'local', 'path' => 'r.pdf', 'original_name' => 'r.pdf',
        'mime' => 'application/pdf', 'size' => 100,
    ]);
    MailAttachment::create([
        'message_id' => $msg->id, 'media_id' => $media->id,
        'filename' => 'r.pdf', 'mime' => 'application/pdf', 'size' => 100,
    ]);
    // Simulate an already-linked Transaction on the same media.
    $account = Account::create([
        'type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0, 'is_active' => true,
    ]);
    $txn = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -42, 'currency' => 'USD', 'description' => 'already captured',
    ]);
    DB::table('mediables')->insert([
        'media_id' => $media->id,
        'mediable_type' => Transaction::class,
        'mediable_id' => $txn->id,
        'role' => 'receipt', // processedRecordsByMessage filters on this
    ]);

    $this->get('/mail')
        ->assertOk()
        ->assertDontSee('Create bill')
        ->assertDontSee('Create transaction');
});

it('counts shown in the header reflect current mail', function () {
    authedInHousehold();
    MailMessage::create([
        'received_at' => now(), 'from_address' => 'a@x', 'subject' => 'x',
        'message_id' => '<c1@x>', 'provider_message_id' => 'c1',
    ]);
    MailMessage::create([
        'received_at' => now()->subDays(3), 'from_address' => 'b@x', 'subject' => 'old',
        'message_id' => '<c2@x>', 'provider_message_id' => 'c2',
    ]);

    $c = Livewire::test('mail-index');
    $counts = $c->get('counts');
    expect($counts['total'])->toBe(2)
        ->and($counts['last_24h'])->toBe(1);
});
