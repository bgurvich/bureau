<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Tag;
use App\Models\Transaction;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\DB;

it('wipes contacts, nulls single-FK references, deletes pivots and morphs', function () {
    authedInHousehold();

    $account = Account::create([
        'type' => 'checking', 'name' => 'Everyday',
        'currency' => 'USD', 'opening_balance' => 0,
    ]);
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Costco']);

    // single FK — counterparty on Transaction.
    $txn = Transaction::create([
        'account_id' => $account->id,
        'occurred_on' => '2026-07-30',
        'amount' => -20,
        'currency' => 'USD',
        'description' => 'Costco',
        'status' => 'cleared',
        'counterparty_contact_id' => $contact->id,
    ]);

    // single FK — account.vendor_contact_id.
    $account->forceFill(['vendor_contact_id' => $contact->id])->save();

    // pivot — contact_contract.
    $contract = Contract::create([
        'kind' => 'agreement',
        'title' => 'NDA',
        'state' => 'active',
    ]);
    $contract->contacts()->attach($contact->id, ['party_role' => 'counterparty']);

    // morph — taggables.
    $tag = Tag::firstOrCreate(['slug' => 'vip'], ['name' => 'VIP']);
    $contact->tags()->attach($tag->id);

    $this->artisan('contacts:wipe', ['--force' => true])->assertExitCode(0);

    expect(Contact::count())->toBe(0)
        // single-FK rows survive, column is null.
        ->and(Transaction::find($txn->id)->counterparty_contact_id)->toBeNull()
        ->and(Account::find($account->id)->vendor_contact_id)->toBeNull()
        // pivot row is gone.
        ->and($contract->fresh()->contacts()->count())->toBe(0)
        // morph row is gone (tag name survives — only the pivot goes).
        ->and(Tag::where('slug', 'vip')->count())->toBe(1)
        ->and(DB::table('taggables')
            ->where('taggable_type', Contact::class)->count())->toBe(0);
});

it('dry-run prints counts without deleting', function () {
    authedInHousehold();

    Contact::create(['kind' => 'org', 'display_name' => 'Keep me']);

    $this->artisan('contacts:wipe', ['--dry-run' => true])->assertExitCode(0);

    expect(Contact::count())->toBe(1);
});

it('scopes to a single household when --household is given', function () {
    $userA = authedInHousehold('Alpha', 'a@example.com');
    $householdA = $userA->defaultHousehold;
    Contact::create(['kind' => 'org', 'display_name' => 'A-only']);

    $userB = authedInHousehold('Beta', 'b@example.com');
    $householdB = $userB->defaultHousehold;
    Contact::create(['kind' => 'org', 'display_name' => 'B-only']);

    CurrentHousehold::set(null);
    $this->artisan('contacts:wipe', ['--household' => $householdA->id, '--force' => true])->assertExitCode(0);

    expect(Contact::withoutGlobalScope('household')->where('household_id', $householdA->id)->count())->toBe(0)
        ->and(Contact::withoutGlobalScope('household')->where('household_id', $householdB->id)->count())->toBe(1);
});
