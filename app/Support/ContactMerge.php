<?php

namespace App\Support;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Merge two Contact rows: move every reference from `loser` onto `winner`,
 * then delete `loser`. Runs inside a single DB transaction so a partial
 * failure rolls the whole thing back — a half-merged contact graph is
 * worse than a failed merge.
 *
 * The reference map is hand-maintained (not derived from foreign-key
 * introspection) because polymorphic tables and pivot tables have
 * different semantics: single-FK columns take a simple UPDATE, pivot
 * tables require a dedup pass so the winner doesn't end up attached to
 * the same record twice via a (contact_id, *) unique index.
 *
 * Adding a new Contact-referencing table? Add its row to `$singleFk`,
 * `$pivots`, or `$morphs` below. Tests in ContactMergeTest exercise one
 * of each so a regression shows up immediately.
 */
final class ContactMerge
{
    /**
     * Merge $loser into $winner. Returns the surviving contact.
     * No-ops (returns $winner) when the two are the same id.
     */
    public static function run(Contact $winner, Contact $loser): Contact
    {
        if ($winner->id === $loser->id) {
            return $winner;
        }

        DB::transaction(function () use ($winner, $loser) {
            foreach (self::$singleFk as [$table, $column]) {
                DB::table($table)
                    ->where($column, $loser->id)
                    ->update([$column => $winner->id]);
            }

            foreach (self::$pivots as [$table, $contactColumn, $partnerColumn]) {
                // Pivot merge with collision dedup: if winner is already
                // attached to partner X and loser is too, dropping the
                // loser row (rather than updating it) avoids a duplicate-
                // key error on the (contact_id, partner_id) unique index.
                $winnerPartners = DB::table($table)
                    ->where($contactColumn, $winner->id)
                    ->pluck($partnerColumn)
                    ->all();
                DB::table($table)
                    ->where($contactColumn, $loser->id)
                    ->whereIn($partnerColumn, $winnerPartners)
                    ->delete();
                DB::table($table)
                    ->where($contactColumn, $loser->id)
                    ->update([$contactColumn => $winner->id]);
            }

            foreach (self::$morphs as [$table, $typeColumn, $idColumn]) {
                DB::table($table)
                    ->where($typeColumn, Contact::class)
                    ->where($idColumn, $loser->id)
                    ->update([$idColumn => $winner->id]);
                // Same dedup concern for morph pivots with unique
                // (type, id, partner) — handled per-table if needed.
            }

            $loser->delete();
        });

        return $winner->fresh() ?? $winner;
    }

    /**
     * IDs of every Contact referenced anywhere in the schema, across
     * single-FK columns, pivot rows, and polymorphic morph rows. Used
     * by the contacts-index "orphaned" filter to find candidates for
     * bulk cleanup after a vendor re-resolve.
     *
     * @return array<int, int>
     */
    public static function referencedContactIds(): array
    {
        $ids = [];
        foreach (self::$singleFk as [$table, $column]) {
            DB::table($table)
                ->whereNotNull($column)
                ->pluck($column)
                ->each(function ($id) use (&$ids) {
                    $ids[(int) $id] = (int) $id;
                });
        }
        foreach (self::$pivots as [$table, $contactColumn]) {
            DB::table($table)
                ->pluck($contactColumn)
                ->each(function ($id) use (&$ids) {
                    $ids[(int) $id] = (int) $id;
                });
        }
        foreach (self::$morphs as [$table, $typeColumn, $idColumn]) {
            DB::table($table)
                ->where($typeColumn, Contact::class)
                ->pluck($idColumn)
                ->each(function ($id) use (&$ids) {
                    $ids[(int) $id] = (int) $id;
                });
        }

        return array_values($ids);
    }

    /**
     * Public accessors so CLI tools (contacts:wipe) reuse the exact
     * same schema graph the merge uses — one source of truth.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function singleFkRefs(): array
    {
        return self::$singleFk;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    public static function pivotRefs(): array
    {
        return self::$pivots;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    public static function morphRefs(): array
    {
        return self::$morphs;
    }

    /** @var array<int, array{0: string, 1: string}> [table, contact_fk_column] */
    private static array $singleFk = [
        ['accounts', 'counterparty_contact_id'],
        ['accounts', 'vendor_contact_id'],
        ['vehicles', 'buyer_contact_id'],
        ['properties', 'buyer_contact_id'],
        ['inventory_items', 'buyer_contact_id'],
        ['health_providers', 'contact_id'],
        ['insurance_policies', 'carrier_contact_id'],
        ['insurance_policies', 'broker_contact_id'],
        ['insurance_policies', 'beneficiary_contact_id'],
        ['online_accounts', 'recovery_contact_id'],
        ['projects', 'client_contact_id'],
        ['recurring_discoveries', 'counterparty_contact_id'],
        ['recurring_rules', 'counterparty_contact_id'],
        ['subscriptions', 'counterparty_contact_id'],
        ['transactions', 'counterparty_contact_id'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> [table, contact_column, partner_column] */
    private static array $pivots = [
        ['contact_contract', 'contact_id', 'contract_id'],
        ['meeting_contact', 'contact_id', 'meeting_id'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> [table, type_column, id_column] */
    private static array $morphs = [
        ['taggables', 'taggable_type', 'taggable_id'],
        ['mediables', 'mediable_type', 'mediable_id'],
        ['task_subjects', 'subject_type', 'subject_id'],
        ['note_subjects', 'subject_type', 'subject_id'],
        ['transaction_subjects', 'subject_type', 'subject_id'],
    ];
}
