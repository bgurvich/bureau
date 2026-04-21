<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MediaFolder;

/**
 * Canonical per-household folders that every capture/import surface
 * routes into. Auto-seeded lazily the first time each slug is asked
 * for — so a clean checkout doesn't need a migration or seeder to
 * get the Inbox/Records Media view filterable by capture source.
 *
 * Slugs map 1:1 to the media_folders.path column (scoped by
 * household via the model's global scope). Labels surface in the
 * filter dropdown on /records?tab=media.
 */
final class MediaFolders
{
    public const INVENTORY = 'inventory';

    public const RECEIPTS = 'receipts';

    public const BILLS = 'bills';

    public const DOCUMENTS = 'documents';

    public const POST = 'post';

    public const STATEMENTS = 'statements';

    /** @var array<string, string> path => user-visible label */
    private const LABELS = [
        self::INVENTORY => 'Inventory',
        self::RECEIPTS => 'Receipts',
        self::BILLS => 'Bills',
        self::DOCUMENTS => 'Documents',
        self::POST => 'Post',
        self::STATEMENTS => 'Statements',
    ];

    /**
     * Return the MediaFolder id for the given slug, creating the row
     * on first request. Null when there's no current household (e.g.
     * unauthenticated ingestion paths) — callers can skip folder
     * assignment in that case rather than crashing.
     */
    public static function idFor(string $slug): ?int
    {
        if (! isset(self::LABELS[$slug])) {
            return null;
        }
        $householdId = CurrentHousehold::id();
        if (! $householdId) {
            return null;
        }

        $folder = MediaFolder::withoutGlobalScope('household')
            ->where('household_id', $householdId)
            ->where('path', $slug)
            ->first();
        if ($folder) {
            return (int) $folder->id;
        }

        return (int) MediaFolder::create([
            'household_id' => $householdId,
            'path' => $slug,
            'label' => self::LABELS[$slug],
            'active' => true,
        ])->id;
    }

    /**
     * Return the set of known capture-source labels, keyed by slug.
     * Used by /records Media filter and the capture-photo picker.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
