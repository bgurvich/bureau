<?php

use App\Models\Account;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Transaction;
use App\Models\Vehicle;
use Livewire\Livewire;

it('Transaction::syncSubjects + inverse relation Vehicle::linkedTransactions', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);

    $txn = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => now()->toDateString(),
        'amount' => -42.00, 'currency' => 'USD', 'description' => 'Oil change', 'status' => 'cleared',
    ]);
    $txn->syncSubjects([['type' => Vehicle::class, 'id' => $vehicle->id]]);

    expect($txn->subjects()->first()->id)->toBe($vehicle->id)
        ->and($vehicle->linkedTransactions()->pluck('id')->all())->toContain($txn->id);
});

it('Inspector saves transaction subjects on create and reloads on edit', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $vehicle = Vehicle::create(['kind' => 'car', 'model' => 'Civic']);
    $contract = Contract::create(['kind' => 'insurance', 'title' => 'Geico']);

    Livewire::test('inspector')
        ->call('openInspector', 'transaction')
        ->set('account_id', $account->id)
        ->set('occurred_on', '2026-03-15')
        ->set('amount', '-180.00')
        ->set('currency', 'USD')
        ->set('description', 'Annual insurance')
        ->set('status', 'cleared')
        ->set('subject_refs', ['vehicle:'.$vehicle->id, 'contract:'.$contract->id])
        ->call('save');

    $txn = Transaction::firstOrFail();
    $subjectIds = $txn->subjects()->pluck('id')->all();
    expect($subjectIds)->toContain($vehicle->id)
        ->and($subjectIds)->toContain($contract->id);

    // Reopen — subject_refs populated.
    $c = Livewire::test('inspector')->call('openInspector', 'transaction', $txn->id);
    expect($c->get('subject_refs'))->toContain('vehicle:'.$vehicle->id)
        ->and($c->get('subject_refs'))->toContain('contract:'.$contract->id);
});

it('Property::linkedTransactions surfaces all transactions linked to that property', function () {
    authedInHousehold();
    $account = Account::create(['type' => 'bank', 'name' => 'Main', 'currency' => 'USD', 'opening_balance' => 0]);
    $property = Property::create(['kind' => 'home', 'name' => 'Our house']);

    $rent = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-03-01',
        'amount' => -2200, 'currency' => 'USD', 'description' => 'Rent', 'status' => 'cleared',
    ]);
    $paint = Transaction::create([
        'account_id' => $account->id, 'occurred_on' => '2026-03-10',
        'amount' => -800, 'currency' => 'USD', 'description' => 'Paint living room', 'status' => 'cleared',
    ]);
    $rent->syncSubjects([['type' => Property::class, 'id' => $property->id]]);
    $paint->syncSubjects([['type' => Property::class, 'id' => $property->id]]);

    expect($property->linkedTransactions()->count())->toBe(2);
});
