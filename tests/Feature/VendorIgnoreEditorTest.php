<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Contact;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use Livewire\Livewire;

it('hydrates from household.data and persists patterns on save', function () {
    $household = authedInHousehold()->defaultHousehold;
    $household->forceFill(['data' => ['vendor_ignore_patterns' => "foo\nbar"]])->save();
    CurrentHousehold::set($household->fresh());

    $component = Livewire::test('vendor-ignore-editor')
        ->assertSet('patterns', "foo\nbar")
        ->set('patterns', "foo\nbar\nbaz")
        ->call('save')
        ->assertSet('savedMessage', __('Saved.'));

    expect(data_get(CurrentHousehold::get()->fresh()->data, 'vendor_ignore_patterns'))
        ->toBe("foo\nbar\nbaz");
});

it('re-resolve button runs VendorReresolver and surfaces a summary', function () {
    $household = authedInHousehold()->defaultHousehold;

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $ugly = Contact::create(['kind' => 'org', 'display_name' => 'Purchase Authorized', 'is_vendor' => true]);
    foreach (['Purchase authorized on 07/30 Costco #1', 'Purchase authorized on 08/02 Costco #2'] as $desc) {
        Transaction::create([
            'account_id' => $account->id,
            'occurred_on' => '2026-07-30',
            'amount' => -10,
            'currency' => 'USD',
            'description' => $desc,
            'status' => 'cleared',
            'counterparty_contact_id' => $ugly->id,
        ]);
    }

    $component = Livewire::test('vendor-ignore-editor')
        ->set('patterns', 'purchase authorized on \d+/\d+')
        ->call('save')
        ->call('reresolve');

    $costco = Contact::where('display_name', 'Costco')->first();
    expect($costco)->not->toBeNull()
        ->and(Transaction::where('counterparty_contact_id', $costco->id)->count())->toBe(2);

    // Message text echoes the summary shape — just check it has content.
    $msg = $component->get('reresolveMessage');
    expect($msg)->not->toBeNull()
        ->and($msg)->toContain('touched')
        ->and($msg)->toContain('new');
});
