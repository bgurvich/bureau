<?php

declare(strict_types=1);

use App\Models\Contact;
use Livewire\Livewire;

it('primary inspector ignores subentity-edit-open, modal instance responds', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);

    // Primary inspector (asModal = false by default).
    $primary = Livewire::test('inspector')
        ->dispatch('subentity-edit-open', type: 'contact', id: $contact->id);
    expect($primary->get('open'))->toBeFalse(); // primary stayed silent

    // Modal inspector (asModal = true).
    $modal = Livewire::test('inspector', ['asModal' => true])
        ->dispatch('subentity-edit-open', type: 'contact', id: $contact->id);
    expect($modal->get('open'))->toBeTrue()
        ->and($modal->get('type'))->toBe('contact')
        ->and($modal->get('id'))->toBe($contact->id);
});

it('modal inspector ignores inspector-open so the primary drawer is not double-opened', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);

    $modal = Livewire::test('inspector', ['asModal' => true])
        ->dispatch('inspector-open', type: 'contact', id: $contact->id);

    expect($modal->get('open'))->toBeFalse();
});

it('close keeps asModal flag intact so the modal instance stays on its own channel', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);

    $modal = Livewire::test('inspector', ['asModal' => true])
        ->dispatch('subentity-edit-open', type: 'contact', id: $contact->id)
        ->call('close');

    expect($modal->get('open'))->toBeFalse()
        ->and($modal->get('asModal'))->toBeTrue();

    // Still responds to subentity-edit-open after a close-open cycle.
    $modal->dispatch('subentity-edit-open', type: 'contact', id: $contact->id);
    expect($modal->get('open'))->toBeTrue();
});

it('ContactForm save persists rename + dispatches inspector-form-saved so the shell can close', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);

    Livewire::test('inspector.contact-form', ['id' => $contact->id])
        ->set('display_name', 'Acme (renamed)')
        ->call('save')
        ->assertDispatched('inspector-saved', type: 'contact', id: $contact->id)
        ->assertDispatched('inspector-form-saved', type: 'contact', id: $contact->id);

    expect($contact->fresh()->display_name)->toBe('Acme (renamed)');
});

it('persists contact_roles on save and clears them when all boxes unchecked', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'person', 'display_name' => 'Aunt Sue']);

    Livewire::test('inspector.contact-form', ['id' => $contact->id])
        ->set('contact_roles', ['family', 'emergency_contact'])
        ->call('save');

    expect($contact->fresh()->contact_roles)->toBe(['family', 'emergency_contact']);

    // Re-open + clear — saving with an empty array writes null (not the
    // stale previous set).
    Livewire::test('inspector.contact-form', ['id' => $contact->id])
        ->set('contact_roles', [])
        ->call('save');

    expect($contact->fresh()->contact_roles)->toBeNull();
});

it('rejects invalid role slugs on save (Rule::in catalog enforcement)', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'person', 'display_name' => 'Aunt Sue']);

    Livewire::test('inspector.contact-form', ['id' => $contact->id])
        ->set('contact_roles', ['family', 'made_up_role'])
        ->call('save')
        ->assertHasErrors(['contact_roles.*']);

    expect($contact->fresh()->contact_roles)->toBeNull();
});

it('primary save does NOT dispatch subentity-edit-saved (event is modal-only)', function () {
    authedInHousehold();
    $contact = Contact::create(['kind' => 'org', 'display_name' => 'Acme']);

    Livewire::test('inspector.contact-form', ['id' => $contact->id])
        ->set('display_name', 'Acme B')
        ->call('save')
        ->assertNotDispatched('subentity-edit-saved')
        ->assertDispatched('inspector-saved', type: 'contact');
});
