<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;

#[Fillable(['name', 'email', 'password', 'default_household_id', 'locale', 'timezone', 'date_format', 'time_format', 'week_starts_on', 'theme'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, WebAuthnAuthentication;

    protected $attributes = [
        'locale' => 'en',
        'timezone' => 'UTC',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'week_starts_on' => 0,
        'theme' => 'dusk',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'week_starts_on' => 'integer',
        ];
    }

    /** @return BelongsToMany<Household, $this> */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_user')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /** @return BelongsTo<Household, $this> */
    public function defaultHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'default_household_id');
    }

    /** @return array<string, string> */
    public static function availableLocales(): array
    {
        return ['en' => 'English'];
    }

    /** @return array<string, string> */
    public static function availableThemes(): array
    {
        // Dusk sits between light and dark — a soft warm-stone palette
        // tuned for long sessions. Dusk (comfort) shares the palette
        // and floors every text-xs / text-[10px|11px] utility at
        // text-sm for long-reading legibility. See resources/css/app.css
        // for the token remap and resources/js/app.ts for the resolver.
        return [
            'system' => 'System',
            'light' => 'Light',
            'dusk' => 'Dusk',
            'dusk-comfort' => 'Dusk (comfort)',
            'dark' => 'Dark',
            'retro' => 'Retro',
        ];
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name ?? '')) ?: [];
        $letters = array_map(fn (string $p) => mb_substr($p, 0, 1), array_filter($parts));

        return mb_strtoupper(implode('', array_slice($letters, 0, 2))) ?: '?';
    }
}
