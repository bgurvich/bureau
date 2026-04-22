<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import contacts from a CSV produced by ContactsExportController (or a
 * reasonable hand-rolled equivalent). Duplicates are matched on
 * display_name case-insensitively:
 *
 *   - no match  → create a new Contact + attach tags + apply category.
 *   - has match → fill ONLY the columns that are currently empty on the
 *     existing contact (never overwrites non-empty fields). match_patterns
 *     is additive (dedup case-insensitive), tags are additive (slugs
 *     attached that aren't already).
 *
 * Category lookup is by name within the current household (case-insensitive).
 * Tag lookup is by slug; missing tags are auto-created.
 */
final class ContactsCsvImporter
{
    public const EXPECTED_COLUMNS = [
        'display_name', 'kind', 'organization', 'first_name', 'last_name',
        'email', 'phone', 'favorite', 'is_vendor', 'is_customer',
        'tax_id', 'category', 'match_patterns', 'tags', 'roles', 'birthday', 'notes',
    ];

    /**
     * @return array{created:int, merged:int, skipped:int, errors:array<int, string>}
     */
    public static function import(string $contents): array
    {
        $summary = ['created' => 0, 'merged' => 0, 'skipped' => 0, 'errors' => []];

        $rows = self::parseCsv($contents);
        if ($rows === null) {
            $summary['errors'][] = __('Could not parse the CSV file.');

            return $summary;
        }
        if ($rows === []) {
            $summary['errors'][] = __('CSV has no data rows.');

            return $summary;
        }

        $header = array_map(fn ($v) => trim(strtolower((string) $v)), array_shift($rows));
        if (! in_array('display_name', $header, true)) {
            $summary['errors'][] = __('CSV is missing the required display_name column.');

            return $summary;
        }

        $categoryIndex = Category::query()
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower((string) $c->name))
            ->map(fn ($c) => (int) $c->id)
            ->all();

        DB::transaction(function () use ($rows, $header, &$summary, &$categoryIndex) {
            foreach ($rows as $rowNum => $raw) {
                if ($raw === [] || ! is_array($raw)) {
                    continue;
                }
                $data = self::mapRow($header, $raw);
                $name = trim((string) ($data['display_name'] ?? ''));
                if ($name === '') {
                    $summary['skipped']++;

                    continue;
                }

                $existing = Contact::query()
                    ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                    ->first();

                try {
                    if ($existing) {
                        self::mergeInto($existing, $data, $categoryIndex);
                        $summary['merged']++;
                    } else {
                        self::createFresh($data, $categoryIndex);
                        $summary['created']++;
                    }
                } catch (\Throwable $e) {
                    $summary['skipped']++;
                    $summary['errors'][] = __('Row :n (:name): :err', [
                        'n' => $rowNum + 2, // +1 for 0-index, +1 for header row
                        'name' => $name,
                        'err' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $summary;
    }

    /**
     * @return array<int, array<int, string>>|null
     */
    private static function parseCsv(string $contents): ?array
    {
        // Strip UTF-8 BOM if present — exported files prepend it for Excel.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return null;
        }
        fwrite($fh, $contents);
        rewind($fh);

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $row
     * @return array<string, string>
     */
    private static function mapRow(array $header, array $row): array
    {
        $out = [];
        foreach ($header as $idx => $col) {
            $out[$col] = isset($row[$idx]) ? (string) $row[$idx] : '';
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, int>  $categoryIndex
     */
    private static function createFresh(array $data, array &$categoryIndex): void
    {
        $kind = trim($data['kind'] ?? '') ?: 'person';
        $emails = self::csvToArray($data['email'] ?? '');
        $phones = self::csvToArray($data['phone'] ?? '');

        $payload = [
            'kind' => $kind,
            'display_name' => trim($data['display_name']),
            'organization' => self::nullIfEmpty($data['organization'] ?? null),
            'first_name' => self::nullIfEmpty($data['first_name'] ?? null),
            'last_name' => self::nullIfEmpty($data['last_name'] ?? null),
            'emails' => $emails === [] ? null : $emails,
            'phones' => $phones === [] ? null : $phones,
            'favorite' => self::toBool($data['favorite'] ?? '0'),
            'is_vendor' => self::toBool($data['is_vendor'] ?? '0'),
            'is_customer' => self::toBool($data['is_customer'] ?? '0'),
            'tax_id' => self::nullIfEmpty($data['tax_id'] ?? null),
            'notes' => self::nullIfEmpty($data['notes'] ?? null),
            'match_patterns' => self::normalisePatterns($data['match_patterns'] ?? ''),
            'category_id' => self::categoryId($data['category'] ?? '', $categoryIndex),
            'contact_roles' => self::parseRoles($data['roles'] ?? ''),
            'birthday' => self::parseBirthday($data['birthday'] ?? ''),
        ];

        $contact = Contact::create($payload);
        self::attachTags($contact, $data['tags'] ?? '');
    }

    /**
     * Fill in missing fields only — never clobber non-empty values on an
     * existing contact. match_patterns is additive (dedup); tags additive.
     *
     * @param  array<string, string>  $data
     * @param  array<string, int>  $categoryIndex
     */
    private static function mergeInto(Contact $c, array $data, array &$categoryIndex): void
    {
        $updates = [];

        $fillIfEmpty = function (string $column, ?string $incoming) use ($c, &$updates) {
            if ($incoming === null || trim($incoming) === '') {
                return;
            }
            if ($c->{$column} === null || $c->{$column} === '') {
                $updates[$column] = trim($incoming);
            }
        };

        $fillIfEmpty('organization', $data['organization'] ?? null);
        $fillIfEmpty('first_name', $data['first_name'] ?? null);
        $fillIfEmpty('last_name', $data['last_name'] ?? null);
        $fillIfEmpty('tax_id', $data['tax_id'] ?? null);
        $fillIfEmpty('notes', $data['notes'] ?? null);

        // kind overrides the default 'person' seed only when existing is
        // the default AND the CSV has a more specific value.
        if (($data['kind'] ?? '') !== '' && ($c->kind === null || $c->kind === '')) {
            $updates['kind'] = trim($data['kind']);
        }

        // emails / phones: fill when the existing array is empty.
        $existingEmails = is_array($c->emails) ? $c->emails : [];
        if ($existingEmails === []) {
            $fromCsv = self::csvToArray($data['email'] ?? '');
            if ($fromCsv !== []) {
                $updates['emails'] = $fromCsv;
            }
        }
        $existingPhones = is_array($c->phones) ? $c->phones : [];
        if ($existingPhones === []) {
            $fromCsv = self::csvToArray($data['phone'] ?? '');
            if ($fromCsv !== []) {
                $updates['phones'] = $fromCsv;
            }
        }

        // category: fill only when existing has none.
        if ($c->category_id === null) {
            $catId = self::categoryId($data['category'] ?? '', $categoryIndex);
            if ($catId !== null) {
                $updates['category_id'] = $catId;
            }
        }

        // match_patterns: additive, dedup case-insensitive, skip when new
        // pattern is just the display-name fingerprint (the patternList
        // self-heal seeds that automatically).
        $incomingPatterns = self::normalisePatterns($data['match_patterns'] ?? '');
        if ($incomingPatterns !== null) {
            $existing = VendorReresolver::parsePatterns((string) ($c->match_patterns ?? ''));
            $existingLower = array_map(fn ($p) => mb_strtolower($p), $existing);
            $displayFp = VendorReresolver::fingerprint((string) $c->display_name);
            $appended = $existing;
            $seenLower = array_flip($existingLower);
            foreach (VendorReresolver::parsePatterns($incomingPatterns) as $p) {
                $pl = mb_strtolower($p);
                if (isset($seenLower[$pl]) || $pl === $displayFp) {
                    continue;
                }
                $appended[] = $p;
                $seenLower[$pl] = true;
            }
            if (count($appended) !== count($existing)) {
                $updates['match_patterns'] = implode("\n", $appended);
            }
        }

        // Favorite / role flags: OR-in rather than overwrite — no way to
        // intentionally turn OFF a flag via CSV, matches "don't clobber
        // non-empty" semantics for booleans.
        foreach (['favorite', 'is_vendor', 'is_customer'] as $flag) {
            if (self::toBool($data[$flag] ?? '0') && ! $c->{$flag}) {
                $updates[$flag] = true;
            }
        }

        // Roles additive — union existing with incoming, skip invalid
        // slugs. Never removes a role the contact already has.
        $incomingRoles = self::parseRoles($data['roles'] ?? '');
        if ($incomingRoles !== null) {
            $existingRoles = is_array($c->contact_roles) ? $c->contact_roles : [];
            $merged = array_values(array_unique(array_merge($existingRoles, $incomingRoles)));
            if (count($merged) !== count($existingRoles)) {
                $updates['contact_roles'] = $merged;
            }
        }

        // Birthday: fill only when the existing contact has none.
        if ($c->birthday === null) {
            $incomingBday = self::parseBirthday($data['birthday'] ?? '');
            if ($incomingBday !== null) {
                $updates['birthday'] = $incomingBday;
            }
        }

        if ($updates !== []) {
            $c->forceFill($updates)->save();
        }

        self::attachTags($c, $data['tags'] ?? '');
    }

    private static function toBool(string $v): bool
    {
        $v = strtolower(trim($v));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    /** @return array<int, string> */
    private static function csvToArray(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];

        return array_values(array_filter($parts, fn ($s) => $s !== ''));
    }

    private static function nullIfEmpty(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * Parse the comma-separated roles cell, filter against the known
     * slug catalog (silently dropping unknowns so typos don't poison
     * the JSON column).
     *
     * @return array<int, string>|null
     */
    private static function parseRoles(string $raw): ?array
    {
        $slugs = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim($s),
            explode(',', $raw)
        ), fn ($s) => $s !== '')));
        if ($slugs === []) {
            return null;
        }
        $valid = array_keys(Enums::contactRoles());

        return array_values(array_intersect($slugs, $valid));
    }

    /**
     * Parse a YYYY-MM-DD birthday cell. Returns null for empty/invalid
     * input so bad data doesn't block the row from importing. Carbon
     * will accept looser formats (e.g. "May 10 1985"); we fall back to
     * strict Y-m-d, then try Carbon as a courtesy.
     */
    private static function parseBirthday(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Convert pipes back to newlines for the stored column. */
    private static function normalisePatterns(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(['|', "\r\n", "\r"], "\n", $raw);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($l) => $l !== ''));

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  array<string, int>  $categoryIndex
     */
    private static function categoryId(string $name, array &$categoryIndex): ?int
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return null;
        }

        return $categoryIndex[$key] ?? null;
    }

    /**
     * Attach any tag slug in $raw (comma-separated) that the contact isn't
     * already tagged with. Missing tags are auto-created (kind=general).
     */
    private static function attachTags(Contact $c, string $raw): void
    {
        $slugs = array_values(array_unique(array_filter(array_map(
            fn ($s) => Str::slug(trim($s)),
            explode(',', $raw)
        ), fn ($s) => $s !== '')));
        if ($slugs === []) {
            return;
        }

        $existingByContact = $c->tags()->pluck('tags.slug')->all();
        $toAttach = array_values(array_diff($slugs, $existingByContact));
        if ($toAttach === []) {
            return;
        }

        $ids = [];
        foreach ($toAttach as $slug) {
            $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => Str::title(str_replace('-', ' ', $slug))]);
            $ids[] = $tag->id;
        }
        $c->tags()->syncWithoutDetaching($ids);
    }
}
