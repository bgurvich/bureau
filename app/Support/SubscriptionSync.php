<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Subscription;

/**
 * Central place to auto-create and auto-link Subscription rows.
 *
 * Called by:
 *   - RecurringRuleObserver::created — when a new outflow rule lands
 *     (manual inspector create, CSV seed, or recurring-pattern discovery
 *     acceptance), we create the matching Subscription so it shows up on
 *     /subscriptions immediately.
 *   - ContractObserver::created — when a contract's counterparty already
 *     has an active subscription, auto-link so the cancellation affordance
 *     flows through without manual wiring.
 */
class SubscriptionSync
{
    /**
     * Turn a recurring outflow rule into a Subscription. No-op if:
     *   - amount is non-negative (income/transfer rules aren't subscriptions),
     *   - a subscription already wraps this rule,
     *   - the rule isn't active.
     */
    public static function fromRecurringRule(RecurringRule $rule): ?Subscription
    {
        if (! $rule->active) {
            return null;
        }
        if ((float) $rule->amount >= 0) {
            return null;
        }
        $existing = Subscription::withoutGlobalScopes()->where('recurring_rule_id', $rule->id)->first();
        if ($existing) {
            return $existing;
        }

        $monthly = self::monthlyMultiplier((string) $rule->rrule);

        // Explicit household_id — the observer context may run outside a
        // request (console commands, queued jobs) where CurrentHousehold is
        // unset. The rule itself is always household-scoped, so we inherit.
        $subscription = Subscription::forceCreate([
            'household_id' => $rule->household_id,
            'name' => $rule->title ?: __('Untitled subscription'),
            'counterparty_contact_id' => $rule->counterparty_contact_id,
            'recurring_rule_id' => $rule->id,
            'contract_id' => self::findMatchingContractId($rule),
            'state' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            // Preserve the rule's sign — outflow rules (the only ones
            // that land here per the amount<0 guard above) stay
            // negative in the cached value, matching the ledger's
            // amount convention everywhere else in the app.
            'monthly_cost_cached' => $monthly !== null ? $monthly * (float) $rule->amount : null,
            'currency' => $rule->currency ?? 'USD',
        ]);

        return $subscription;
    }

    /**
     * Back-link a new Contract into any existing Subscriptions that share
     * its counterparty. Multiple subscriptions with the same counterparty
     * all point at the same contract (one contract often covers multiple
     * billing lines — e.g. monthly base + per-seat add-ons).
     *
     * @return int number of subscriptions updated
     */
    public static function linkContract(Contract $contract): int
    {
        $counterpartyId = $contract->contacts()
            ->wherePivot('party_role', 'counterparty')
            ->value('contacts.id');
        if (! $counterpartyId) {
            return 0;
        }

        return Subscription::where('counterparty_contact_id', $counterpartyId)
            ->whereNull('contract_id')
            ->update(['contract_id' => $contract->id]);
    }

    private static function findMatchingContractId(RecurringRule $rule): ?int
    {
        if (! $rule->counterparty_contact_id) {
            return null;
        }
        $contract = Contract::whereHas('contacts', fn ($q) => $q
            ->where('contacts.id', $rule->counterparty_contact_id)
            ->where('contact_contract.party_role', 'counterparty')
        )->first();

        return $contract?->id;
    }

    public static function monthlyMultiplier(string $rrule): ?float
    {
        if (preg_match('/FREQ=MONTHLY/i', $rrule)) {
            $interval = preg_match('/INTERVAL=(\d+)/i', $rrule, $m) ? max(1, (int) $m[1]) : 1;

            return 1.0 / $interval;
        }
        if (preg_match('/FREQ=WEEKLY/i', $rrule)) {
            $interval = preg_match('/INTERVAL=(\d+)/i', $rrule, $m) ? max(1, (int) $m[1]) : 1;

            return (52.0 / 12.0) / $interval;
        }
        if (preg_match('/FREQ=YEARLY/i', $rrule)) {
            $interval = preg_match('/INTERVAL=(\d+)/i', $rrule, $m) ? max(1, (int) $m[1]) : 1;

            return (1.0 / 12.0) / $interval;
        }

        return null;
    }
}
