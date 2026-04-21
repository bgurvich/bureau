<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mints a signed-in session for a disposable ZAP test user and prints the
 * session cookie value on stdout. Used by scripts/zap-authed.sh to run ZAP
 * against authenticated routes without the scanner needing to understand
 * Livewire's login flow.
 *
 * The command writes a session row directly into the `sessions` table with
 * the `login_web_*` marker Laravel looks up when resolving the authenticated
 * user, so subsequent requests carrying the session cookie authenticate as
 * the target user.
 */
class ZapSessionCommand extends Command
{
    protected $signature = 'zap:session
                            {--email=zap-scan@example.test : Email for the disposable test user}
                            {--household=ZAP Scan Household : Name of the household to attach}';

    protected $description = 'Create a signed-in session cookie for ZAP authenticated scans';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $householdName = (string) $this->option('household');

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'ZAP Scan', 'password' => Hash::make(Str::random(40))],
        );

        $household = Household::firstOrCreate(['name' => $householdName]);
        if (! $user->households()->where('households.id', $household->id)->exists()) {
            $user->households()->attach($household->id, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);
        }
        if (! $user->default_household_id) {
            $user->forceFill(['default_household_id' => $household->id])->save();
        }

        $sessionId = Str::random(40);
        $payload = [
            '_token' => Str::random(40),
            // Laravel's SessionGuard stores the authenticated user id under
            // the key `login_web_<sha1(SessionGuard::class)>` — match exactly
            // so the guard picks it up on the next request.
            'login_web_'.sha1(SessionGuard::class) => $user->id,
        ];

        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'ZAP-Auth-Scan',
            'payload' => base64_encode(serialize($payload)),
            'last_activity' => time(),
        ]);

        $cookieName = config('session.cookie', 'laravel_session');
        // Laravel encrypts cookies via Middleware\EncryptCookies; the server
        // decrypts and extracts the raw session id. To feed ZAP a single
        // header value we emulate the same envelope: encrypt the session id
        // string as JSON `{value,expires,mac}` that EncryptCookies accepts.
        $encrypted = Crypt::encrypt($sessionId, false);

        $this->line("{$cookieName}={$encrypted}");

        return self::SUCCESS;
    }
}
