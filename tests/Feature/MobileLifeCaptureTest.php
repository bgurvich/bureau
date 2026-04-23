<?php

it('mobile capture screen renders the 5 life-event tiles', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    $response = $this->get(route('mobile.capture'));
    $response->assertOk()
        ->assertSee('Life event')
        ->assertSee('Food')
        ->assertSee('Journal entry')
        ->assertSee('Decision')
        ->assertSee('Reading / watching')
        ->assertSee('Goal')
        // Dispatches inspector-open, which the mobile layout's inspector
        // listens to — no dedicated capture route per type needed.
        ->assertSee("Livewire.dispatch('inspector-open', { type: 'food_entry' })", escape: false)
        ->assertSee("Livewire.dispatch('inspector-open', { type: 'goal' })", escape: false);
});

it('mobile layout includes the inspector drawer components', function () {
    $user = authedInHousehold();
    $this->actingAs($user);

    // Any authed mobile page should carry the inspector so tile dispatches land.
    $this->get(route('mobile.home'))->assertOk()->assertSee('wire:snapshot', escape: false);
});
