<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'login')->name('login');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware(['auth', 'preferences', 'household'])->group(function () {
    Route::livewire('/', 'dashboard')->name('dashboard');

    $stubs = [
        ['fiscal.accounts', '/accounts', 'Accounts'],
        ['fiscal.transactions', '/transactions', 'Transactions'],
        ['fiscal.recurring', '/bills', 'Bills & Income'],
        ['calendar.tasks', '/tasks', 'Tasks'],
        ['calendar.meetings', '/meetings', 'Meetings'],
        ['relationships.contacts', '/contacts', 'Contacts'],
        ['relationships.contracts', '/contracts', 'Contracts'],
        ['relationships.insurance', '/insurance', 'Insurance'],
        ['assets.properties', '/properties', 'Properties'],
        ['assets.vehicles', '/vehicles', 'Vehicles'],
        ['assets.inventory', '/inventory', 'Inventory'],
        ['records.documents', '/documents', 'Documents'],
        ['records.media', '/media', 'Media'],
        ['records.notes', '/notes', 'Notes'],
        ['health.providers', '/health/providers', 'Health providers'],
        ['health.prescriptions', '/health/prescriptions', 'Prescriptions'],
        ['health.appointments', '/health/appointments', 'Appointments'],
        ['time.projects', '/time/projects', 'Projects'],
        ['time.entries', '/time/entries', 'Time entries'],
    ];

    foreach ($stubs as [$name, $path, $title]) {
        Route::get($path, fn () => view('stubs.coming-soon', ['title' => $title]))->name($name);
    }
});
