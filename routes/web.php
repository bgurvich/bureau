<?php

use App\Http\Controllers\BookkeeperExportController;
use App\Http\Controllers\MediaFileController;
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
    Route::livewire('/profile', 'profile')->name('profile');
    Route::livewire('/finance', 'finance-overview')->name('fiscal.overview');
    Route::livewire('/accounts', 'accounts-index')->name('fiscal.accounts');
    Route::livewire('/transactions', 'transactions-index')->name('fiscal.transactions');
    Route::livewire('/bills', 'bills-index')->name('fiscal.recurring');
    Route::livewire('/bookkeeper', 'bookkeeper')->name('bookkeeper');
    Route::post('/bookkeeper/export', BookkeeperExportController::class)->name('bookkeeper.export');
    Route::livewire('/calendar', 'calendar-index')->name('calendar.index');
    Route::livewire('/tasks', 'tasks-index')->name('calendar.tasks');
    Route::livewire('/meetings', 'meetings-index')->name('calendar.meetings');
    Route::livewire('/contacts', 'contacts-index')->name('relationships.contacts');
    Route::livewire('/contracts', 'contracts-index')->name('relationships.contracts');
    Route::livewire('/insurance', 'insurance-index')->name('relationships.insurance');
    Route::livewire('/documents', 'documents-index')->name('records.documents');
    Route::livewire('/notes', 'notes-index')->name('records.notes');
    Route::livewire('/time/projects', 'projects-index')->name('time.projects');
    Route::livewire('/time/entries', 'time-entries-index')->name('time.entries');
    Route::livewire('/properties', 'properties-index')->name('assets.properties');
    Route::livewire('/vehicles', 'vehicles-index')->name('assets.vehicles');
    Route::livewire('/inventory', 'inventory-index')->name('assets.inventory');
    Route::livewire('/health/providers', 'health-providers-index')->name('health.providers');
    Route::livewire('/health/prescriptions', 'prescriptions-index')->name('health.prescriptions');
    Route::livewire('/health/appointments', 'appointments-index')->name('health.appointments');
    Route::livewire('/media', 'media-index')->name('records.media');
    Route::get('/media/{media}/file', MediaFileController::class)->name('media.file');
    Route::livewire('/online-accounts', 'online-accounts-index')->name('records.online_accounts');

    Route::prefix('m')->group(function () {
        Route::livewire('/', 'mobile.capture')->name('mobile.capture');
        Route::livewire('/capture/inventory', 'mobile.capture-inventory')->name('mobile.capture.inventory');
        Route::livewire('/capture/note', 'mobile.capture-note')->name('mobile.capture.note');
        Route::livewire('/capture/photo', 'mobile.capture-photo')->name('mobile.capture.photo');
        Route::livewire('/inbox', 'mobile.inbox')->name('mobile.inbox');
        Route::livewire('/search', 'mobile.search')->name('mobile.search');
        Route::livewire('/me', 'mobile.me')->name('mobile.me');
    });
});
