<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending invite to join a household. The plain token is returned once
 * from create() so it can be embedded in the invitation email URL; only
 * its sha256 hash is stored, making a leaked DB row useless on its own.
 * One row covers a full lifecycle — created → accepted (or expired /
 * revoked via delete).
 */
class HouseholdInvitation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Create a fresh invitation and return [model, plain-token]. The
     * plain token lives only in memory on the calling request (so the
     * mailer can build a URL); the DB stores its hash.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(
        Household $household,
        string $email,
        string $role,
        ?User $invitedBy,
        int $ttlDays = 7,
    ): array {
        $plain = Str::random(48);
        $invitation = self::create([
            'household_id' => $household->id,
            'invited_by_user_id' => $invitedBy?->id,
            'email' => mb_strtolower(trim($email)),
            'role' => $role,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        return [$invitation, $plain];
    }

    /** Look up by the URL-provided plain token. Null if no match. */
    public static function findByToken(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }

    /**
     * Refresh the token + expiry so the user can resend without
     * creating a parallel pending row. Old token is invalidated in the
     * same write.
     *
     * @return string the new plain token
     */
    public function rotateToken(int $ttlDays = 7): string
    {
        $plain = Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays($ttlDays),
        ])->save();

        return $plain;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
