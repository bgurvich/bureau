<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Severity classification for each attention-radar signal kind.
 * Shared between the radar's PHP component (for total + criticalTotal
 * rollups) and its template (for tile sorting). Kept here rather than
 * inlined twice because severity assignments are an editorial choice
 * the user is likely to revisit as the app's feel evolves.
 */
final class RadarSeverity
{
    /** @var array<string, string> */
    public const MAP = [
        'overdue_tasks' => 'warn',
        'overdue_bills' => 'critical',
        'unreconciled' => 'info',
        'pending_reminders' => 'warn',
        'trials_ending_soon' => 'critical',
        'autorenewing_contracts_ending_soon' => 'warn',
        'gift_cards_expiring_soon' => 'warn',
        'domains_expiring_soon' => 'warn',
        'tax_payments_due_soon' => 'critical',
        'bills_inbox' => 'info',
        'unprocessed_inventory' => 'info',
        'budget_envelopes_at_risk' => 'critical',
        'spending_anomalies' => 'warn',
        'savings_goals_ready_to_close' => 'info',
        'unfinished_morning_rituals' => 'warn',
        'unfinished_evening_rituals' => 'warn',
        'expiring_pet_vaccinations' => 'warn',
        'overdue_pet_checkups' => 'critical',
        'expiring_pet_licenses' => 'warn',
        'decision_follow_ups_due' => 'warn',
        'goals_behind_pace' => 'warn',
        'goals_stale' => 'warn',
        'integrations_needing_reconnect' => 'critical',
        'vehicle_services_due_soon' => 'warn',
        'listings_expiring_soon' => 'warn',
        'pet_preventive_care_due_soon' => 'warn',
    ];

    public static function of(string $kind): string
    {
        return self::MAP[$kind] ?? 'warn';
    }

    public static function isCritical(string $kind): bool
    {
        return self::of($kind) === 'critical';
    }

    /** Rank used for tile ordering — lower = more acute. */
    public static function rank(string $kind): int
    {
        return match (self::of($kind)) {
            'critical' => 0,
            'warn' => 1,
            'info' => 2,
            default => 1,
        };
    }
}
