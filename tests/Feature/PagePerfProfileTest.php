<?php

use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Baseline page-perf profile. Seeds the DemoDataSeeder against a fresh
 * household, hits each authenticated page once, and logs query count +
 * SQL time + wall time per route. Not a regression test — run manually
 * when you want to re-baseline:
 *
 *   ./vendor/bin/pest --filter=profiles_main_pages
 *
 * Stays out of the default suite via the 'perf' group.
 */
it('profiles main pages', function () {
    authedInHousehold();
    $this->seed(DemoDataSeeder::class);

    /** @var array<int, array{name: string, label: string}> $routes */
    $routes = [
        ['name' => 'dashboard', 'label' => 'Dashboard'],
        ['name' => 'fiscal.overview', 'label' => 'Finance hub'],
        ['name' => 'fiscal.ledger', 'label' => 'Ledger hub'],
        ['name' => 'fiscal.transactions', 'label' => 'Transactions'],
        ['name' => 'fiscal.accounts', 'label' => 'Accounts'],
        ['name' => 'fiscal.recurring', 'label' => 'Recurring hub'],
        ['name' => 'fiscal.bills', 'label' => 'Bills'],
        ['name' => 'fiscal.subscriptions', 'label' => 'Subscriptions'],
        ['name' => 'fiscal.yoy', 'label' => 'YoY spending'],
        ['name' => 'fiscal.budgets', 'label' => 'Budgets'],
        ['name' => 'fiscal.planning', 'label' => 'Planning hub'],
        ['name' => 'fiscal.rules', 'label' => 'Rules hub'],
        ['name' => 'fiscal.category_rules', 'label' => 'Category rules'],
        ['name' => 'fiscal.tag_rules', 'label' => 'Tag rules'],
        ['name' => 'fiscal.savings_goals', 'label' => 'Savings goals'],
        ['name' => 'fiscal.inbox', 'label' => 'Inbox'],
        ['name' => 'calendar.index', 'label' => 'Calendar hub'],
        ['name' => 'calendar.tasks', 'label' => 'Tasks'],
        ['name' => 'calendar.meetings', 'label' => 'Meetings'],
        ['name' => 'life.schedule', 'label' => 'Schedule hub'],
        ['name' => 'life.checklists.index', 'label' => 'Checklists'],
        ['name' => 'relationships.contacts', 'label' => 'Contacts'],
        ['name' => 'assets.index', 'label' => 'Assets hub'],
        ['name' => 'assets.properties', 'label' => 'Properties'],
        ['name' => 'assets.vehicles', 'label' => 'Vehicles'],
        ['name' => 'assets.inventory', 'label' => 'Inventory'],
        ['name' => 'pets.index', 'label' => 'Pets'],
        ['name' => 'health.index', 'label' => 'Health hub'],
        ['name' => 'health.providers', 'label' => 'Health providers'],
        ['name' => 'health.prescriptions', 'label' => 'Prescriptions'],
        ['name' => 'health.appointments', 'label' => 'Appointments'],
        ['name' => 'records.media', 'label' => 'Media'],
        ['name' => 'records.mail', 'label' => 'Mail'],
        ['name' => 'records.online_accounts', 'label' => 'Online accounts'],
        ['name' => 'records.in_case_of', 'label' => 'In-case-of pack'],
        ['name' => 'tags.index', 'label' => 'Tags'],
        ['name' => 'review', 'label' => 'Weekly review'],
        ['name' => 'reconcile', 'label' => 'Reconcile workbench'],
        ['name' => 'bookkeeper', 'label' => 'Bookkeeper'],
        ['name' => 'settings', 'label' => 'Settings'],
        ['name' => 'profile', 'label' => 'Profile'],
    ];

    /** @var array<int, array{label: string, queries: int, sqlMs: float, wallMs: float, status: int}> $results */
    $results = [];

    foreach ($routes as $route) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $response = $this->get(route($route['name']));
        $wallMs = (microtime(true) - $start) * 1000;

        $log = DB::getQueryLog();
        $queries = count($log);
        $sqlMs = array_sum(array_column($log, 'time'));
        DB::disableQueryLog();

        $results[] = [
            'label' => $route['label'],
            'queries' => $queries,
            'sqlMs' => $sqlMs,
            'wallMs' => $wallMs,
            'status' => $response->status(),
        ];
    }

    usort($results, fn ($a, $b) => $b['wallMs'] <=> $a['wallMs']);

    fwrite(STDERR, "\n");
    fwrite(STDERR, str_pad('PAGE', 26).str_pad('QUERIES', 10).str_pad('SQL MS', 12).str_pad('WALL MS', 12)."STATUS\n");
    fwrite(STDERR, str_repeat('-', 70)."\n");
    foreach ($results as $r) {
        fwrite(STDERR, sprintf(
            "%-26s%-10d%-12.1f%-12.1f%d\n",
            $r['label'],
            $r['queries'],
            $r['sqlMs'],
            $r['wallMs'],
            $r['status'],
        ));
    }

    expect($results)->not->toBeEmpty();
})->group('perf');

it('profiles one page query-by-query', function () {
    $routeName = getenv('PERF_ROUTE') ?: 'dashboard';
    authedInHousehold();
    $this->seed(DemoDataSeeder::class);

    DB::enableQueryLog();
    $this->get(route($routeName));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    /** @var array<string, array{count: int, sqlMs: float}> $grouped */
    $grouped = [];
    foreach ($log as $q) {
        $sig = preg_replace('/`[^`]+`\.`[^`]+`\.`([^`]+)`|`([^`]+)`/', '$1$2', (string) $q['query']);
        $sig = preg_replace('/\s+/', ' ', (string) $sig) ?: '';
        $key = mb_substr($sig, 0, 80);
        if (! isset($grouped[$key])) {
            $grouped[$key] = ['count' => 0, 'sqlMs' => 0.0];
        }
        $grouped[$key]['count']++;
        $grouped[$key]['sqlMs'] += (float) $q['time'];
    }
    uasort($grouped, fn ($a, $b) => $b['count'] <=> $a['count']);

    fwrite(STDERR, "\n");
    fwrite(STDERR, sprintf("%s: %d queries, %.1f ms SQL\n", strtoupper((string) $routeName), count($log), array_sum(array_column($log, 'time'))));
    fwrite(STDERR, str_repeat('-', 100)."\n");
    foreach ($grouped as $sig => $info) {
        fwrite(STDERR, sprintf("%3dx  %6.1fms  %s\n", $info['count'], $info['sqlMs'], $sig));
    }

    expect($log)->not->toBeEmpty();
})->group('perf');
