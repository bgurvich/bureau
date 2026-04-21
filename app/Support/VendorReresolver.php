<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use App\Models\Transaction;

/**
 * Re-run the vendor auto-detect step against existing Transactions
 * after the user's ignore-patterns or contact database has changed.
 * The intent is to fix rows that got assigned to ugly auto-created
 * contacts like "Purchase Authorized On" when the user later adds
 * filler-stripping rules.
 *
 * Manual assignments are preserved by a soft heuristic: a Transaction
 * is treated as "auto-assigned" (and therefore safe to re-resolve) if
 * its counterparty is null, OR the counterparty's display_name /
 * organization fingerprints match the transaction's current
 * description fingerprint. When a user manually picks a contact whose
 * name is unrelated to the description, that mismatch makes us skip
 * the row. The heuristic is imperfect but beats requiring a schema
 * change for an explicit "auto vs manual" flag.
 */
final class VendorReresolver
{
    /**
     * @return array{touched: int, matched_existing: int, created: int, cleared: int, skipped_manual: int}
     */
    public static function run(): array
    {
        $summary = ['touched' => 0, 'matched_existing' => 0, 'created' => 0, 'cleared' => 0, 'skipped_manual' => 0];

        // Pre-pass 1: fingerprint every Transaction description we're
        // considering. Only rows with a non-empty description are
        // candidates (blank-description imports never got a vendor
        // auto-assigned anyway). Collected into a count map so the
        // ≥ 2 auto-create threshold behaves like the import flow.
        $fingerprintCounts = [];
        $rowFingerprints = [];
        Transaction::query()
            ->whereNotNull('description')
            ->select(['id', 'description', 'counterparty_contact_id'])
            ->chunkById(500, function ($chunk) use (&$fingerprintCounts, &$rowFingerprints) {
                foreach ($chunk as $t) {
                    $fp = self::fingerprint((string) $t->description);
                    $rowFingerprints[(int) $t->id] = $fp;
                    if ($fp !== '') {
                        $fingerprintCounts[$fp] = ($fingerprintCounts[$fp] ?? 0) + 1;
                    }
                }
            });

        // Pre-pass 2: fingerprint every Contact once so per-row
        // lookups are O(1); cache (display_name, organization,
        // match_patterns) by id for the auto-vs-manual heuristic and
        // the explicit-patterns lookup below.
        $contactByFingerprint = [];
        /** @var array<int, array{display_name: string, organization: string, match_patterns: string}> */
        $contactById = [];
        /** @var array<int, array{0: int, 1: string}> [contact_id, pattern] pairs, walked per row */
        $matchPatternList = [];
        Contact::query()->select(['id', 'display_name', 'organization', 'match_patterns'])
            ->chunk(500, function ($chunk) use (&$contactByFingerprint, &$contactById, &$matchPatternList) {
                foreach ($chunk as $c) {
                    foreach ([$c->display_name, $c->organization] as $candidate) {
                        if (! is_string($candidate) || $candidate === '') {
                            continue;
                        }
                        $fp = self::fingerprint($candidate);
                        if ($fp !== '' && ! isset($contactByFingerprint[$fp])) {
                            $contactByFingerprint[$fp] = (int) $c->id;
                        }
                    }

                    // Intentionally NO auto-heal here. If the user
                    // just added ignore patterns and wants the re-
                    // resolver to move rows off a now-stale auto-
                    // contact, seeding patterns on that ugly contact
                    // would anchor rows to it forever. Healing only
                    // runs on the import flow (patternList()) where
                    // the goal is "don't re-create existing vendors"
                    // rather than "migrate to new ones".
                    $existingPatterns = is_string($c->match_patterns) ? $c->match_patterns : '';

                    $contactById[(int) $c->id] = [
                        'display_name' => is_string($c->display_name) ? $c->display_name : '',
                        'organization' => is_string($c->organization) ? $c->organization : '',
                        'match_patterns' => $existingPatterns,
                    ];
                    foreach (self::parsePatterns($existingPatterns) as $p) {
                        $matchPatternList[] = [(int) $c->id, $p];
                    }
                }
            });

        // Main pass: decide per transaction.
        Transaction::query()
            ->whereNotNull('description')
            ->select(['id', 'description', 'counterparty_contact_id'])
            ->chunkById(500, function ($chunk) use (
                &$summary, &$fingerprintCounts, &$rowFingerprints,
                &$contactByFingerprint, &$contactById, &$matchPatternList
            ) {
                foreach ($chunk as $t) {
                    $newFp = $rowFingerprints[(int) $t->id] ?? '';
                    $currentId = $t->counterparty_contact_id !== null ? (int) $t->counterparty_contact_id : null;
                    $descLower = mb_strtolower((string) $t->description);

                    // Explicit match_patterns always win — user has
                    // declared "this contact owns descriptions like
                    // X". First hit in the list is assigned; list
                    // order is Contact insertion order which the user
                    // controls by row-adding patterns in the inspector.
                    $matchedByPattern = self::firstPatternMatch($descLower, $matchPatternList);
                    if ($matchedByPattern !== null) {
                        if ($currentId !== $matchedByPattern) {
                            Transaction::whereKey($t->id)->update(['counterparty_contact_id' => $matchedByPattern]);
                            $summary['matched_existing']++;
                            $summary['touched']++;
                        }

                        continue;
                    }

                    // Manual-assignment guard: a counterparty is
                    // treated as "auto-set" (safe to re-resolve) when
                    // the contact's display_name or organization
                    // appears as a substring of the transaction
                    // description — exactly the shape that import-
                    // time fingerprinting would have produced. If
                    // neither substring is present, the user picked
                    // the contact deliberately and we back off.
                    if ($currentId !== null && isset($contactById[$currentId])) {
                        $name = mb_strtolower($contactById[$currentId]['display_name']);
                        $org = mb_strtolower($contactById[$currentId]['organization']);
                        $auto = ($name !== '' && str_contains($descLower, $name))
                            || ($org !== '' && str_contains($descLower, $org));
                        if (! $auto) {
                            $summary['skipped_manual']++;

                            continue;
                        }
                    }

                    // Degenerate fingerprint ("" after filler strip) —
                    // nothing reliable to match. Clear any existing
                    // counterparty if it was a stale auto-match so
                    // the row stops pointing at a wrong contact.
                    if ($newFp === '') {
                        if ($currentId !== null) {
                            Transaction::whereKey($t->id)->update(['counterparty_contact_id' => null]);
                            $summary['cleared']++;
                            $summary['touched']++;
                        }

                        continue;
                    }

                    // Existing contact matches → use it.
                    if (isset($contactByFingerprint[$newFp])) {
                        $newId = $contactByFingerprint[$newFp];
                        if ($currentId !== $newId) {
                            Transaction::whereKey($t->id)->update(['counterparty_contact_id' => $newId]);
                            $summary['matched_existing']++;
                            $summary['touched']++;
                        }

                        continue;
                    }

                    // No match — auto-create only for repeating
                    // descriptions (≥ 2 occurrences), same threshold
                    // the import flow uses. Single-occurrence rows
                    // with no match stay/go to null counterparty.
                    if (($fingerprintCounts[$newFp] ?? 0) >= 2) {
                        $humanized = self::humanize((string) $t->description);
                        // Seed match_patterns with the fingerprint so
                        // later renames of the display_name don't
                        // detach the rows from this contact on the
                        // next re-resolve — the pattern persists.
                        $contact = Contact::create([
                            'kind' => 'org',
                            'display_name' => $humanized !== '' ? $humanized : $newFp,
                            'is_vendor' => true,
                            'match_patterns' => $newFp,
                        ]);
                        $newId = (int) $contact->id;
                        $contactByFingerprint[$newFp] = $newId;
                        $contactById[$newId] = [
                            'display_name' => (string) $contact->display_name,
                            'organization' => '',
                            'match_patterns' => $newFp,
                        ];
                        $matchPatternList[] = [$newId, $newFp];

                        Transaction::whereKey($t->id)->update(['counterparty_contact_id' => $newId]);
                        $summary['created']++;
                        $summary['touched']++;

                        continue;
                    }

                    // Lone row with no match — if it used to point at
                    // a now-stale auto-contact whose fingerprint matches
                    // the description, clear it. Otherwise, we already
                    // returned up top (manual-guard case) so this is
                    // "was null, stays null" — no-op.
                    if ($currentId !== null) {
                        Transaction::whereKey($t->id)->update(['counterparty_contact_id' => null]);
                        $summary['cleared']++;
                        $summary['touched']++;
                    }
                }
            });

        return $summary;
    }

    private static function fingerprint(string $raw): string
    {
        // Mirrors the statements-import fingerprinter exactly — apply
        // household-configured ignore patterns first, then the same
        // digit-cut + non-alpha strip + "first two meaningful words"
        // shaping.
        $raw = DescriptionNormalizer::stripIgnoredPatterns($raw);
        $lower = mb_strtolower($raw);
        $lower = (string) preg_replace('/[\d#*].*$/', '', $lower);
        $lower = (string) preg_replace('/[^a-z\s]+/', ' ', $lower);
        $words = preg_split('/\s+/', trim($lower)) ?: [];
        $meaningful = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4));

        return implode(' ', array_slice($meaningful, 0, 2));
    }

    private static function humanize(string $raw): string
    {
        $raw = DescriptionNormalizer::stripIgnoredPatterns($raw);
        $cleaned = (string) preg_replace('/[\d#*].*$/i', '', $raw);
        $words = preg_split('/\s+/', trim($cleaned)) ?: [];
        $meaningful = array_filter($words, fn ($w) => mb_strlen($w) >= 3);
        $joined = implode(' ', array_slice($meaningful, 0, 3));

        return ucwords(mb_strtolower($joined));
    }

    /**
     * Parse the multi-line match_patterns textarea into a list of
     * trimmed, non-empty regex bodies. Empty / blank lines skipped.
     *
     * @return array<int, string>
     */
    public static function parsePatterns(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return $out;
    }

    /**
     * Walk every Contact's match_patterns once and return a flat list
     * of (contact_id, pattern) pairs. Insertion order is preserved —
     * firstPatternMatch() returns the first hit. Also self-heals:
     * contacts with null/empty patterns get their display_name's
     * fingerprint seeded so future word-order variations across
     * descriptions don't spawn duplicate auto-contacts.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public static function patternList(): array
    {
        $out = [];
        Contact::query()->select(['id', 'display_name', 'match_patterns'])
            ->chunk(500, function ($chunk) use (&$out) {
                foreach ($chunk as $c) {
                    $patterns = is_string($c->match_patterns) ? $c->match_patterns : '';
                    if (trim($patterns) === '') {
                        $displayFp = is_string($c->display_name) ? self::fingerprint($c->display_name) : '';
                        if ($displayFp !== '') {
                            $c->forceFill(['match_patterns' => $displayFp])->save();
                            $patterns = $displayFp;
                        }
                    }
                    foreach (self::parsePatterns($patterns) as $p) {
                        $out[] = [(int) $c->id, $p];
                    }
                }
            });

        return $out;
    }

    /**
     * Test every (contact_id, pattern) pair against a lowercased
     * description and return the first matching contact_id. Null if
     * no pattern hits. Matching is case-insensitive via the 'i' flag
     * so plain-substring patterns ("costco") work the same as
     * anchored regex.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs
     */
    public static function firstPatternMatch(string $descLower, array $pairs): ?int
    {
        foreach ($pairs as [$id, $pattern]) {
            $result = @preg_match('#'.str_replace('#', '\#', $pattern).'#iu', $descLower);
            if ($result === 1) {
                return $id;
            }
        }

        return null;
    }
}
