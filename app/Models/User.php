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

#[Fillable(['name', 'email', 'password', 'default_household_id', 'locale', 'timezone', 'date_format', 'time_format', 'week_starts_on', 'theme'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'locale' => 'en',
        'timezone' => 'UTC',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'week_starts_on' => 0,
        'theme' => 'system',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'week_starts_on' => 'integer',
        ];
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_user')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

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
        return ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark', 'retro' => 'Retro'];
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name ?? '')) ?: [];
        $letters = array_map(fn (string $p) => mb_substr($p, 0, 1), array_filter($parts));

        return mb_strtoupper(implode('', array_slice($letters, 0, 2))) ?: '?';
    }
}
