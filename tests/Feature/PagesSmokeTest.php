<?php

use App\Models\Tag;

/**
 * Page-level smoke tests: every authenticated Livewire route renders a 200
 * without throwing. This is deliberately dumb — no assertions about content —
 * because the goal is "does the page boot without exceptions on an empty
 * household". If a refactor breaks imports, routes, layouts, or computed
 * properties, this test catches it before the feature test suites even run.
 *
 * Routes with required parameters (e.g. `/tags/{slug}`) are excluded from
 * the generic sweep and get their own focused test.
 */
beforeEach(function () {
    authedInHousehold();
});

dataset('app_routes', [
    ['dashboard'],
    ['review'],
    ['profile'],
    ['settings'],
    ['fiscal.overview'],
    ['fiscal.accounts'],
    ['fiscal.transactions'],
    ['fiscal.recurring'],
    ['fiscal.subscriptions'],
    ['fiscal.yoy'],
    ['fiscal.budgets'],
    ['fiscal.category_rules'],
    ['fiscal.tag_rules'],
    ['fiscal.savings_goals'],
    ['fiscal.import.statements'],
    ['fiscal.inbox'],
    ['bookkeeper'],
    ['calendar.index'],
    ['calendar.tasks'],
    ['calendar.meetings'],
    ['life.checklists.index'],
    ['relationships.contacts'],
    ['relationships.contracts'],
    ['relationships.insurance'],
    ['records.documents'],
    ['records.notes'],
    ['records.media'],
    ['records.mail'],
    ['records.online_accounts'],
    ['records.in_case_of'],
    ['time.projects'],
    ['time.entries'],
    ['assets.properties'],
    ['assets.vehicles'],
    ['assets.inventory'],
    ['health.providers'],
    ['health.prescriptions'],
    ['health.appointments'],
    ['tags.index'],
]);

it('renders :route for an empty household', function (string $routeName) {
    $this->get(route($routeName))->assertOk();
})->with('app_routes');

dataset('mobile_routes', [
    ['mobile.home'],
    ['mobile.capture'],
    ['mobile.capture.inventory'],
    ['mobile.capture.note'],
    ['mobile.capture.photo'],
    ['mobile.inbox'],
    ['mobile.search'],
    ['mobile.me'],
]);

it('renders mobile PWA :route', function (string $routeName) {
    $this->get(route($routeName))->assertOk();
})->with('mobile_routes');

// Routes with bound parameters — covered individually with realistic fixtures.

it('renders the tag hub for a tag that exists', function () {
    $tag = Tag::create(['name' => 'groceries', 'slug' => 'groceries']);

    $this->get(route('tags.show', ['slug' => $tag->slug]))->assertOk();
});

it('/login renders for guests', function () {
    auth()->logout();
    $this->get(route('login'))->assertOk();
});
