<?php

use App\Http\Controllers\AddressAutocompleteController;
use App\Http\Controllers\BookkeeperExportController;
use App\Http\Controllers\ContactsExportController;
use App\Http\Controllers\GmailOAuthController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalExportController;
use App\Http\Controllers\PostmarkInboundController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\WebAuthn\WebAuthnLoginController;
use App\Http\Controllers\WebAuthn\WebAuthnRegisterController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/postmark/inbound', PostmarkInboundController::class)
    ->middleware('webhook.basic:postmark')
    ->name('webhooks.postmark.inbound');

Route::post('/webhooks/paypal/inbound', PayPalWebhookController::class)
    ->name('webhooks.paypal.inbound');

// Bookkeeper portal — external (non-user) session scoped to a single
// household via a time-boxed grant. Public token consumer + explicit
// expired page; everything else lives behind the portal.session
// middleware and is read-only.
Route::get('/portal/expired', [PortalController::class, 'expired'])
    ->name('portal.expired');
Route::get('/portal/{token}', [PortalController::class, 'consume'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:10,1')
    ->name('portal.consume');
Route::post('/portal/logout', [PortalController::class, 'logout'])
    ->name('portal.logout');
Route::middleware('portal.session')->group(function () {
    Route::livewire('/portal', 'portal-dashboard')->name('portal.dashboard');
    Route::get('/portal/export', PortalExportController::class)
        ->name('portal.export');
});

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'login')->name('login');

    // Invitation acceptance — the page resolves the token, and on submit
    // creates a fresh User (or validates an existing one) and attaches
    // them to the inviting household. Guest-only because a successful
    // accept logs the user in; already-signed-in users should sign out
    // to a different account or ask for a new invite.
    Route::livewire('/join/{token}', 'accept-invitation')->name('invitations.accept');

    Route::post('/login/magic', [MagicLinkController::class, 'request'])
        ->middleware('throttle:6,1')
        ->name('magic-link.request');
    Route::get('/login/magic/{user}', [MagicLinkController::class, 'consume'])
        ->name('magic-link.consume');

    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
        ->name('social.callback');

    // Passkey (WebAuthn) assertion: browser POSTs for a challenge, then posts
    // the signed assertion back. Succeeds only if a stored credential verifies.
    Route::post('/webauthn/login/options', [WebAuthnLoginController::class, 'options'])
        ->middleware('throttle:30,1')->name('webauthn.login.options');
    Route::post('/webauthn/login', [WebAuthnLoginController::class, 'login'])
        ->middleware('throttle:10,1')->name('webauthn.login');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware(['auth', 'preferences', 'household'])->group(function () {
    Route::livewire('/', 'dashboard')->name('dashboard');
    Route::livewire('/review', 'weekly-review')->name('review');
    Route::livewire('/reconcile', 'reconciliation-workbench')->name('reconcile');
    Route::livewire('/profile', 'profile')->name('profile');
    Route::livewire('/settings', 'settings-index')->name('settings');
    Route::livewire('/finance', 'finance-hub')->name('fiscal.overview');
    Route::livewire('/ledger', 'ledger-hub')->name('fiscal.ledger');
    Route::livewire('/accounts', 'accounts-index')->name('fiscal.accounts');
    Route::livewire('/transactions', 'transactions-index')->name('fiscal.transactions');
    // /recurring is the hub; /bills stays for deep-links. The `fiscal.recurring`
    // name now points at the hub so mobile/home and alerts-bell keep routing to
    // the right place — hub defaults to the Bills tab so the user still lands
    // on the bills list after one click.
    Route::livewire('/recurring', 'recurring-hub')->name('fiscal.recurring');
    Route::livewire('/bills', 'bills-index')->name('fiscal.bills');
    Route::livewire('/subscriptions', 'subscriptions-index')->name('fiscal.subscriptions');
    Route::livewire('/finance/yoy', 'yoy-spending')->name('fiscal.yoy');
    Route::livewire('/budgets', 'budgets-index')->name('fiscal.budgets');
    Route::livewire('/planning', 'planning-hub')->name('fiscal.planning');
    Route::livewire('/category-rules', 'category-rules-index')->name('fiscal.category_rules');
    Route::livewire('/tag-rules', 'tag-rules-index')->name('fiscal.tag_rules');
    Route::livewire('/rules', 'rules-hub')->name('fiscal.rules');
    Route::livewire('/savings-goals', 'savings-goals-index')->name('fiscal.savings_goals');
    Route::livewire('/import/statements', 'statements-import')->name('fiscal.import.statements');
    Route::livewire('/bookkeeper', 'bookkeeper')->name('bookkeeper');
    Route::post('/bookkeeper/export', BookkeeperExportController::class)->name('bookkeeper.export');
    Route::livewire('/calendar', 'calendar-index')->name('calendar.index');
    Route::livewire('/tasks', 'tasks-index')->name('calendar.tasks');
    Route::livewire('/meetings', 'meetings-index')->name('calendar.meetings');
    Route::livewire('/life/checklists', 'checklists-index')->name('life.checklists.index');
    Route::livewire('/life/checklists/today', 'checklists-today')->name('life.checklists.today');
    Route::livewire('/schedule', 'schedule-hub')->name('life.schedule');
    Route::livewire('/contacts', 'contacts-index')->name('relationships.contacts');
    Route::get('/contacts/export', ContactsExportController::class)->name('relationships.contacts.export');
    Route::livewire('/contracts', 'contracts-index')->name('relationships.contracts');
    Route::livewire('/insurance', 'insurance-index')->name('relationships.insurance');
    Route::livewire('/records', 'records-hub')->name('records.index');
    Route::livewire('/post', 'physical-mail-index')->name('records.post');
    Route::livewire('/documents', 'documents-index')->name('records.documents');
    Route::livewire('/notes', 'notes-index')->name('records.notes');
    Route::livewire('/journal', 'journal-index')->name('life.journal');
    Route::livewire('/time/projects', 'projects-index')->name('time.projects');
    Route::livewire('/time/entries', 'time-entries-index')->name('time.entries');
    Route::livewire('/properties', 'properties-index')->name('assets.properties');
    Route::livewire('/vehicles', 'vehicles-index')->name('assets.vehicles');
    Route::livewire('/inventory', 'inventory-index')->name('assets.inventory');
    Route::livewire('/domains', 'domains-index')->name('assets.domains');
    Route::livewire('/meters', 'meter-readings-index')->name('assets.meters');
    Route::livewire('/tax', 'tax-hub')->name('fiscal.tax');
    Route::livewire('/assets', 'assets-hub')->name('assets.index');
    Route::livewire('/pets', 'pets-hub')->name('pets.index');
    Route::livewire('/health', 'health-hub')->name('health.index');
    Route::livewire('/health/providers', 'health-providers-index')->name('health.providers');
    Route::livewire('/health/prescriptions', 'prescriptions-index')->name('health.prescriptions');
    Route::livewire('/health/appointments', 'appointments-index')->name('health.appointments');
    Route::livewire('/media', 'media-index')->name('records.media');
    Route::get('/media/{media}/file', MediaFileController::class)->name('media.file');
    Route::get('/media/{media}/thumb', [MediaFileController::class, 'thumb'])->name('media.thumb');
    Route::livewire('/mail', 'mail-index')->name('records.mail');
    Route::livewire('/inbox', 'inbox')->name('fiscal.inbox');
    Route::livewire('/online-accounts', 'online-accounts-index')->name('records.online_accounts');
    Route::livewire('/in-case-of', 'in-case-of-pack')->name('records.in_case_of');
    Route::livewire('/tags', 'tags-index')->name('tags.index');
    Route::livewire('/tags/{slug}', 'tag-hub')->name('tags.show');

    Route::get('/integrations/gmail/connect', [GmailOAuthController::class, 'connect'])->name('integrations.gmail.connect');
    Route::get('/integrations/gmail/callback', [GmailOAuthController::class, 'callback'])->name('integrations.gmail.callback');

    // Passkey (WebAuthn) registration: browser posts for attestation options,
    // user's authenticator creates a credential, browser posts the attestation
    // back. A confirmed session is required so unauthenticated clients cannot
    // attach a credential to someone else's account.
    Route::post('/webauthn/register/options', [WebAuthnRegisterController::class, 'options'])->name('webauthn.register.options');
    Route::post('/webauthn/register', [WebAuthnRegisterController::class, 'register'])->name('webauthn.register');
    Route::delete('/webauthn/credentials/{credential}', function (string $credential) {
        $user = request()->user();
        abort_unless($user, 401);
        $user->webAuthnCredentials()->whereKey($credential)->delete();

        return response()->noContent();
    })->name('webauthn.credentials.destroy');

    // OSM Nominatim proxy for contact + property address typeahead. Rate-
    // limited to respect the public endpoint's 1-req/sec policy; cached 24h
    // per query to minimize outbound load.
    Route::get('/address/autocomplete', AddressAutocompleteController::class)
        ->middleware('throttle:60,1')
        ->name('address.autocomplete');

    Route::prefix('m')->group(function () {
        Route::livewire('/', 'mobile.home')->name('mobile.home');
        Route::livewire('/capture', 'mobile.capture')->name('mobile.capture');
        Route::livewire('/capture/inventory', 'mobile.capture-inventory')->name('mobile.capture.inventory');
        Route::livewire('/capture/note', 'mobile.capture-note')->name('mobile.capture.note');
        Route::livewire('/capture/photo', 'mobile.capture-photo')->name('mobile.capture.photo');
        // /capture/post merged into /capture/photo with a kind selector —
        // the old name stays for any deep links or bookmarks, redirecting
        // in with ?kind=post so the selector pre-picks the right tab.
        Route::get('/capture/post', fn () => redirect()->route('mobile.capture.photo', ['kind' => 'post']))
            ->name('mobile.capture.post');
        Route::livewire('/inbox', 'mobile.inbox')->name('mobile.inbox');
        Route::livewire('/search', 'mobile.search')->name('mobile.search');
        Route::livewire('/me', 'mobile.me')->name('mobile.me');
    });
});
