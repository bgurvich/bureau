<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Token-backed grant for the external-bookkeeper portal. Raw tokens are
 * displayed to the owner at creation (once) and then only the SHA-256
 * hash is kept. Consumption goes through PortalGrant::findByToken(),
 * which hashes the incoming raw token + verifies the grant is live.
 */
class PortalGrant extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'scope' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_preview' => 'boolean',
    ];

    /** 64 hex chars = 256 bits of entropy. Non-enumerable. */
    public const TOKEN_LENGTH = 64;

    /**
     * Create a grant and return [PortalGrant, rawToken]. The raw token
     * is the ONLY chance the caller has to display it — afterwards only
     * the hash survives.
     *
     * @return array{0: PortalGrant, 1: string}
     */
    public static function issue(
        int $householdId,
        CarbonImmutable $expiresAt,
        ?string $granteeEmail = null,
        ?string $label = null,
        bool $isPreview = false,
    ): array {
        $raw = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));

        $grant = self::create([
            'household_id' => $householdId,
            'grantee_email' => $granteeEmail,
            'label' => $label,
            'token_hash' => hash('sha256', $raw),
            'token_tail' => Str::substr($raw, -6),
            'expires_at' => $expiresAt,
            'scope' => ['fiscal'],
            'is_preview' => $isPreview,
        ]);

        return [$grant, $raw];
    }

    /**
     * Find an ACTIVE grant matching the given raw token. Returns null
     * for: hash miss, revoked, or expired. Caller should also bump
     * last_seen_at on a successful hit.
     */
    public static function findByToken(string $raw): ?self
    {
        if ($raw === '' || strlen($raw) !== self::TOKEN_LENGTH) {
            return null;
        }
        $grant = self::query()
            ->withoutGlobalScope('household')
            ->where('token_hash', hash('sha256', $raw))
            ->first();

        if ($grant === null || $grant->revoked_at !== null) {
            return null;
        }
        if ($grant->expires_at !== null && $grant->expires_at->isPast()) {
            return null;
        }

        return $grant;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->saveQuietly();
    }
}
