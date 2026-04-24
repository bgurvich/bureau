<?php

use App\Models\Account;
use App\Models\ChecklistRun;
use App\Models\Contract;
use App\Models\Decision;
use App\Models\Domain;
use App\Models\Goal;
use App\Models\Integration;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Models\Media;
use App\Models\PetCheckup;
use App\Models\PetLicense;
use App\Models\PetPreventiveCare;
use App\Models\PetVaccination;
use App\Models\RadarSnooze;
use App\Models\RecurringProjection;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Task;
use App\Models\TaxEstimatedPayment;
use App\Models\Transaction;
use App\Models\VehicleServiceLog;
use App\Support\BudgetMonitor;
use App\Support\ChecklistScheduling;
use App\Support\CurrentHousehold;
use App\Support\RadarSeverity;
use App\Support\SpendingAnomalyDetector;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Map of signal_kind (string) → count contributed to total(). The
     * radar tiles all feed this map in the same render pass so snooze
     * filtering + the grand total pull from a single source of truth
     * instead of iterating 25+ computed getters twice.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function signalCounts(): array
    {
        return [
            'overdue_tasks' => $this->overdueTasks,
            'unreconciled' => $this->unreconciled,
            'overdue_bills' => $this->overdueBills,
            'pending_reminders' => $this->pendingReminders,
            'trials_ending_soon' => $this->trialsEndingSoon,
            'autorenewing_contracts_ending_soon' => $this->autorenewingContractsEndingSoon,
            'gift_cards_expiring_soon' => $this->giftCardsExpiringSoon,
            'domains_expiring_soon' => $this->domainsExpiringSoon,
            'tax_payments_due_soon' => $this->taxPaymentsDueSoon,
            'bills_inbox' => $this->billsInbox,
            'unprocessed_inventory' => $this->unprocessedInventory,
            'budget_envelopes_at_risk' => $this->budgetEnvelopesAtRisk,
            'spending_anomalies' => $this->spendingAnomalies,
            'savings_goals_ready_to_close' => $this->savingsGoalsReadyToClose,
            'unfinished_morning_rituals' => $this->unfinishedMorningRituals,
            'unfinished_evening_rituals' => $this->unfinishedEveningRituals,
            'expiring_pet_vaccinations' => $this->expiringPetVaccinations,
            'overdue_pet_checkups' => $this->overduePetCheckups,
            'expiring_pet_licenses' => $this->expiringPetLicenses,
            'decision_follow_ups_due' => $this->decisionFollowUpsDue,
            'goals_behind_pace' => $this->goalsBehindPace,
            'goals_stale' => $this->goalsStale,
            'integrations_needing_reconnect' => $this->integrationsNeedingReconnect,
            'vehicle_services_due_soon' => $this->vehicleServicesDueSoon,
            'listings_expiring_soon' => $this->listingsExpiringSoon,
            'pet_preventive_care_due_soon' => $this->petPreventiveCareDueSoon,
        ];
    }

    /**
     * Kinds the current user has snoozed and which are still in effect.
     * Tiles for these render with a muted "snoozed" affordance instead
     * of their normal row, and they don't contribute to total().
     *
     * @return array<string, \Carbon\Carbon>  kind => snoozed_until
     */
    #[Computed]
    public function snoozes(): array
    {
        $userId = auth()->id();
        $householdId = CurrentHousehold::id();
        if ($userId === null || $householdId === null) {
            return [];
        }

        return RadarSnooze::query()
            ->where('user_id', $userId)
            ->where('household_id', $householdId)
            ->where('snoozed_until', '>', now())
            ->get(['signal_kind', 'snoozed_until'])
            ->mapWithKeys(fn ($r) => [(string) $r->signal_kind => $r->snoozed_until])
            ->all();
    }

    public function isSnoozed(string $kind): bool
    {
        return array_key_exists($kind, $this->snoozes);
    }

    public function snoozeSignal(string $kind, int $days): void
    {
        $days = max(1, min(90, $days));
        $userId = auth()->id();
        $householdId = CurrentHousehold::id();
        if ($userId === null || $householdId === null) {
            return;
        }

        RadarSnooze::updateOrCreate(
            ['user_id' => $userId, 'household_id' => $householdId, 'signal_kind' => $kind],
            ['snoozed_until' => now()->addDays($days)],
        );
        unset($this->snoozes, $this->total);
    }

    public function unsnoozeSignal(string $kind): void
    {
        $userId = auth()->id();
        $householdId = CurrentHousehold::id();
        if ($userId === null || $householdId === null) {
            return;
        }

        RadarSnooze::where('user_id', $userId)
            ->where('household_id', $householdId)
            ->where('signal_kind', $kind)
            ->delete();
        unset($this->snoozes, $this->total);
    }

    #[Computed]
    public function overdueTasks(): int
    {
        return Task::where('state', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    #[Computed]
    public function unreconciled(): int
    {
        return Transaction::where('status', 'pending')->count();
    }

    #[Computed]
    public function overdueBills(): int
    {
        // Autopay projections only surface once >7 days past due without a match
        // (= the auto-charge actually failed). Matched projections never surface.
        $graceCutoff = now()->subDays(7)->toDateString();
        $todayStr = now()->toDateString();

        $baseCount = RecurringProjection::whereIn('status', ['overdue', 'projected'])
            ->where('autopay', false)
            ->where('due_on', '<', $todayStr)
            ->count();

        $autopayOverdue = RecurringProjection::whereIn('status', ['overdue', 'projected'])
            ->where('autopay', true)
            ->where('due_on', '<', $graceCutoff)
            ->count();

        return $baseCount + $autopayOverdue;
    }

    #[Computed]
    public function pendingReminders(): int
    {
        return Reminder::where('state', 'pending')
            ->where('remind_at', '<=', now())
            ->count();
    }

    #[Computed]
    public function trialsEndingSoon(): int
    {
        return Contract::whereNotNull('trial_ends_on')
            ->whereBetween('trial_ends_on', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();
    }

    /**
     * Auto-renewing contracts ending in the next 14 days that still have a
     * known cancellation path. Surfacing them here gives the user the last-
     * chance window to cancel before the next period bills.
     */
    #[Computed]
    public function autorenewingContractsEndingSoon(): int
    {
        return Contract::where('auto_renews', true)
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->where(fn ($q) => $q->whereNotNull('cancellation_url')->orWhereNotNull('cancellation_email'))
            ->count();
    }

    #[Computed]
    public function giftCardsExpiringSoon(): int
    {
        return Account::whereIn('type', ['gift_card', 'prepaid'])
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();
    }

    /**
     * Domains expiring within 30 days that aren't set to auto-renew.
     * Auto-renewing domains drop off the radar because the registrar
     * will handle them — manual renewals are the actionable bucket.
     */
    #[Computed]
    public function domainsExpiringSoon(): int
    {
        return Domain::where('auto_renew', false)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();
    }

    /**
     * Unpaid quarterly estimated-tax rows due within 30 days OR already
     * past due. Drops off once paid_on is set. The 30-day window hits
     * right as the user should be cutting the cheque.
     */
    #[Computed]
    public function taxPaymentsDueSoon(): int
    {
        return TaxEstimatedPayment::whereNull('paid_on')
            ->where('due_on', '<=', now()->addDays(30)->toDateString())
            ->count();
    }

    #[Computed]
    public function billsInbox(): int
    {
        return Media::whereNull('processed_at')
            ->where('ocr_status', 'done')
            ->whereNotNull('ocr_extracted')
            ->count();
    }

    #[Computed]
    public function unprocessedInventory(): int
    {
        return InventoryItem::whereNull('processed_at')->count();
    }

    /**
     * Categories whose month-to-date spend has crossed 80% of their envelope
     * cap. We surface both WARNING and OVER together — the user's action is
     * the same in both cases: inspect the category.
     */
    #[Computed]
    public function budgetEnvelopesAtRisk(): int
    {
        return BudgetMonitor::currentMonthWarningCount();
    }

    /**
     * Recent transactions whose magnitude is >2.5σ above their category's
     * 90-day baseline — "did a routine bill spike unexpectedly?" tile.
     */
    #[Computed]
    public function spendingAnomalies(): int
    {
        return (new SpendingAnomalyDetector)->recentAnomaliesCount();
    }

    /**
     * Active savings goals that have hit their target but haven't been
     * marked achieved yet — prompts the user to celebrate/close them.
     */
    #[Computed]
    public function savingsGoalsReadyToClose(): int
    {
        // Eager-load account.latestBalance so progressRatio() → currentSaved()
        // doesn't fire a round-trip per goal when it checks the linked account
        // balance. Goals with no account_id skip the balance chain entirely.
        return SavingsGoal::with(['account.latestBalance'])
            ->where('state', 'active')
            ->get()
            ->filter(fn ($g) => $g->progressRatio() >= 1.0)
            ->count();
    }

    /**
     * Count of scheduled-today ritual templates in a given time_of_day
     * bucket that haven't been completed yet, surfaced once the bucket's
     * window is over — morning after 11:00 local, evening after 22:00.
     * Skipped runs count as "not missing" (user explicitly opted out).
     */
    private function unfinishedRitualsForBucket(string $bucket, int $afterHour): int
    {
        if (now()->hour < $afterHour) {
            return 0;
        }

        $templates = ChecklistScheduling::templatesScheduledOn(now())
            ->where('time_of_day', $bucket);
        if ($templates->isEmpty()) {
            return 0;
        }

        $ids = $templates->pluck('id')->all();
        $runs = ChecklistRun::whereIn('checklist_template_id', $ids)
            ->where('run_date', now()->toDateString())
            ->get()
            ->keyBy('checklist_template_id');

        return $templates->filter(function ($t) use ($runs) {
            $run = $runs->get($t->id);

            return ! $run || ($run->completed_at === null && $run->skipped_at === null);
        })->count();
    }

    #[Computed]
    public function unfinishedMorningRituals(): int
    {
        return $this->unfinishedRitualsForBucket('morning', 11);
    }

    #[Computed]
    public function unfinishedEveningRituals(): int
    {
        return $this->unfinishedRitualsForBucket('evening', 22);
    }

    /**
     * Pet vaccinations expiring in the next 30 days OR already expired —
     * wider window than the alerts-bell (14d) because the radar is the
     * "what's on my mind this month" surface. Placeholder rows (never
     * administered) don't count here since they're not actionable yet.
     */
    #[Computed]
    public function expiringPetVaccinations(): int
    {
        return PetVaccination::query()
            ->whereNotNull('administered_on')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays(30)->toDateString())
            ->count();
    }

    /** Pet checkups whose next_due_on has passed. */
    #[Computed]
    public function overduePetCheckups(): int
    {
        return PetCheckup::query()
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<', now()->toDateString())
            ->count();
    }

    /** Pet licenses expiring within 30 days or already expired. */
    #[Computed]
    public function expiringPetLicenses(): int
    {
        return PetLicense::query()
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays(30)->toDateString())
            ->count();
    }

    /** Decisions whose follow_up_on has passed with no outcome recorded. */
    #[Computed]
    public function decisionFollowUpsDue(): int
    {
        return Decision::query()
            ->whereNull('outcome')
            ->whereNotNull('follow_up_on')
            ->where('follow_up_on', '<=', now()->toDateString())
            ->count();
    }

    /**
     * Active target-mode goals whose actual progress trails expected
     * pacing by 10% or more — quick SQL approximation of onTrack(),
     * aligned with the listing's amber bar. Count not rows: cheap.
     */
    #[Computed]
    public function goalsBehindPace(): int
    {
        return Goal::query()
            ->where('status', 'active')
            ->where('mode', 'target')
            ->whereNotNull('target_date')
            ->whereNotNull('started_on')
            ->whereNotNull('target_value')
            ->where('target_value', '>', 0)
            ->where('started_on', '<=', now()->toDateString())
            ->where('target_date', '>', 'started_on')
            // elapsed% > progress% + 10 → behind pace
            ->whereRaw('(DATEDIFF(LEAST(CURDATE(), target_date), started_on) / DATEDIFF(target_date, started_on)) > (current_value / target_value) + 0.10')
            ->count();
    }

    /**
     * Active direction-mode goals whose last_reflected_at + cadence has
     * passed (or who have never been reflected on at all). Mirrors the
     * listing's "time for a check-in" chip.
     */
    #[Computed]
    public function goalsStale(): int
    {
        return Goal::query()
            ->where('status', 'active')
            ->where('mode', 'direction')
            ->whereNotNull('cadence_days')
            ->where(function ($q) {
                $q->whereNull('last_reflected_at')
                    ->orWhereRaw('DATE_ADD(last_reflected_at, INTERVAL cadence_days DAY) <= NOW()');
            })
            ->count();
    }

    /**
     * Integrations in status=error — the provider refused our credential
     * and the owner has to re-authenticate (Gmail revoke, password
     * change, TOS accept, etc.). Rows live on /profile > Personal
     * integrations.
     */
    #[Computed]
    public function integrationsNeedingReconnect(): int
    {
        return Integration::query()
            ->where('status', 'error')
            ->count();
    }

    /**
     * Pet preventive-care rows whose next_due_on is within 14 days
     * (or past). Deduped by (pet, kind) so a stale older log doesn't
     * keep firing after a newer dose supersedes it.
     */
    #[Computed]
    public function petPreventiveCareDueSoon(): int
    {
        // Outer table stays un-aliased so BelongsToHousehold's
        // pet_preventive_care.household_id scope still binds. The
        // MAX subquery takes its own alias to avoid the self-join
        // ambiguity.
        return PetPreventiveCare::query()
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<=', now()->addDays(14)->toDateString())
            ->whereRaw('pet_preventive_care.applied_on = (
                SELECT MAX(inner_ppc.applied_on) FROM pet_preventive_care AS inner_ppc
                WHERE inner_ppc.pet_id = pet_preventive_care.pet_id
                  AND inner_ppc.kind = pet_preventive_care.kind
            )')
            ->count();
    }

    /**
     * Live listings whose expiry window is within 7 days (or past).
     * Prompts the owner to either relist / delist / lower price
     * before the platform auto-expires the posting.
     */
    #[Computed]
    public function listingsExpiringSoon(): int
    {
        return Listing::query()
            ->where('status', 'live')
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays(7)->toDateString())
            ->count();
    }

    /**
     * Vehicle services whose next_due_on is within 30 days (or already
     * past). Restricted to the latest log per (vehicle, kind) pair so
     * a stale 2022 oil change with a 2023 due date doesn't keep firing
     * after a newer log supersedes it.
     */
    #[Computed]
    public function vehicleServicesDueSoon(): int
    {
        return VehicleServiceLog::query()
            ->from('vehicle_service_log as vsl')
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<=', now()->addDays(30)->toDateString())
            ->whereRaw('vsl.service_date = (
                SELECT MAX(service_date) FROM vehicle_service_log
                WHERE vehicle_id = vsl.vehicle_id AND kind = vsl.kind
            )')
            ->count();
    }

    #[Computed]
    public function total(): int
    {
        $sum = 0;
        foreach ($this->signalCounts as $kind => $count) {
            if ($this->isSnoozed($kind)) {
                continue;
            }
            $sum += $count;
        }

        return $sum;
    }

    /**
     * Subset of total() constrained to tiles tagged severity=critical.
     * Surfaced separately in the header so the user can see "3 of the
     * 17 things are actually on fire" without counting rose tiles.
     */
    #[Computed]
    public function criticalTotal(): int
    {
        $sum = 0;
        foreach ($this->signalCounts as $kind => $count) {
            if ($this->isSnoozed($kind)) {
                continue;
            }
            if (RadarSeverity::isCritical($kind)) {
                $sum += $count;
            }
        }

        return $sum;
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Attention') }}</h3>
        <span class="flex items-baseline gap-2 text-xs tabular-nums">
            @if ($this->criticalTotal > 0)
                <span class="text-rose-400" title="{{ __('Critical items need action now') }}">
                    {{ $this->criticalTotal }} {{ __('critical') }}
                </span>
                <span class="text-neutral-600">·</span>
            @endif
            <span class="{{ $this->total > 0 ? 'text-amber-400' : 'text-neutral-500' }}">
                {{ $this->total }} {{ $this->total === 1 ? __('item') : __('items') }}
            </span>
        </span>
    </div>

    @if ($this->total === 0)
        <div class="py-6 text-center text-xs text-neutral-600">{{ __('Nothing is waiting on you.') }}</div>
    @else
        @php
            // Per-signal tile specs — one source of truth for label, color,
            // optional href, and kind (used by snooze + future severity).
            $tiles = [
                ['kind' => 'overdue_tasks', 'label' => __('Overdue tasks'), 'color' => 'text-amber-400'],
                ['kind' => 'overdue_bills', 'label' => __('Overdue bills'), 'color' => 'text-rose-400'],
                ['kind' => 'unreconciled', 'label' => __('Unreconciled transactions'), 'color' => 'text-neutral-400'],
                ['kind' => 'pending_reminders', 'label' => __('Due reminders'), 'color' => 'text-amber-400'],
                ['kind' => 'trials_ending_soon', 'label' => __('Trials ending ≤ 7d'), 'color' => 'text-rose-400'],
                ['kind' => 'autorenewing_contracts_ending_soon', 'label' => __('Auto-renewing ≤ 14d'), 'color' => 'text-amber-400', 'href' => route('relationships.contracts')],
                ['kind' => 'gift_cards_expiring_soon', 'label' => __('Gift cards expiring ≤ 30d'), 'color' => 'text-amber-400'],
                ['kind' => 'domains_expiring_soon', 'label' => __('Domains expiring ≤ 30d'), 'color' => 'text-amber-400', 'href' => route('assets.domains', ['status' => 'expiring'])],
                ['kind' => 'tax_payments_due_soon', 'label' => __('Estimated tax due ≤ 30d'), 'color' => 'text-rose-400', 'href' => route('fiscal.tax')],
                ['kind' => 'bills_inbox', 'label' => __('Bills Inbox'), 'color' => 'text-sky-300', 'href' => route('fiscal.inbox')],
                ['kind' => 'unprocessed_inventory', 'label' => __('Unprocessed inventory'), 'color' => 'text-amber-400', 'href' => route('assets.inventory', ['status' => 'unprocessed'])],
                ['kind' => 'budget_envelopes_at_risk', 'label' => __('Envelopes ≥ 80% used'), 'color' => 'text-rose-400', 'href' => route('fiscal.budgets')],
                ['kind' => 'spending_anomalies', 'label' => __('Unusual charges ≤ 7d'), 'color' => 'text-amber-400'],
                ['kind' => 'savings_goals_ready_to_close', 'label' => __('Savings goals hit target'), 'color' => 'text-emerald-400', 'href' => route('fiscal.savings_goals')],
                ['kind' => 'unfinished_morning_rituals', 'label' => __('Unfinished morning routine'), 'color' => 'text-amber-400', 'href' => route('life.checklists.today')],
                ['kind' => 'unfinished_evening_rituals', 'label' => __('Unfinished evening routine'), 'color' => 'text-amber-400', 'href' => route('life.checklists.today')],
                ['kind' => 'expiring_pet_vaccinations', 'label' => __('Pet vaccines expiring / expired'), 'color' => 'text-amber-400', 'href' => route('pets.index', ['tab' => 'vaccinations'])],
                ['kind' => 'overdue_pet_checkups', 'label' => __('Overdue pet checkups'), 'color' => 'text-rose-400', 'href' => route('pets.index', ['tab' => 'checkups'])],
                ['kind' => 'expiring_pet_licenses', 'label' => __('Pet licenses expiring ≤ 30d'), 'color' => 'text-amber-400', 'href' => route('pets.index', ['tab' => 'licenses', 'status' => 'expiring'])],
                ['kind' => 'decision_follow_ups_due', 'label' => __('Decisions awaiting follow-up'), 'color' => 'text-amber-400', 'href' => route('life.decisions', ['status' => 'awaiting_followup'])],
                ['kind' => 'goals_behind_pace', 'label' => __('Goals behind pace'), 'color' => 'text-amber-400', 'href' => route('life.goals', ['mode' => 'target', 'status' => 'active'])],
                ['kind' => 'goals_stale', 'label' => __('Directions due for check-in'), 'color' => 'text-amber-400', 'href' => route('life.goals', ['mode' => 'direction', 'status' => 'active'])],
                ['kind' => 'integrations_needing_reconnect', 'label' => __('Integrations need reconnection'), 'color' => 'text-rose-400', 'href' => route('profile').'#personal-integrations-heading'],
                ['kind' => 'vehicle_services_due_soon', 'label' => __('Vehicle services due ≤ 30d'), 'color' => 'text-amber-400', 'href' => route('assets.vehicle_services')],
                ['kind' => 'listings_expiring_soon', 'label' => __('Listings expiring ≤ 7d'), 'color' => 'text-amber-400', 'href' => route('assets.listings', ['status' => 'live'])],
                ['kind' => 'pet_preventive_care_due_soon', 'label' => __('Pet preventive care due ≤ 14d'), 'color' => 'text-amber-400', 'href' => route('pets.index')],
            ];
            $counts = $this->signalCounts;
            $snoozed = $this->snoozes;
            // Reorder so critical tiles float to the top. Within each
            // severity bucket we preserve the declared order, which
            // carries the existing category grouping.
            usort($tiles, fn ($a, $b) => \App\Support\RadarSeverity::rank($a['kind']) <=> \App\Support\RadarSeverity::rank($b['kind']));
        @endphp
        <ul class="space-y-2 text-sm">
            @foreach ($tiles as $t)
                @php($count = $counts[$t['kind']] ?? 0)
                @if ($count > 0 && ! array_key_exists($t['kind'], $snoozed))
                    <x-radar.tile
                        :kind="$t['kind']"
                        :label="$t['label']"
                        :count="$count"
                        :color="$t['color']"
                        :href="$t['href'] ?? null" />
                @endif
            @endforeach
        </ul>

        @if (count($snoozed) > 0)
            <details class="mt-4 text-xs text-neutral-500">
                <summary class="cursor-pointer hover:text-neutral-300">
                    {{ __(':n snoozed', ['n' => count($snoozed)]) }}
                </summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($snoozed as $kind => $until)
                        @php($spec = collect($tiles)->firstWhere('kind', $kind))
                        <li class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-neutral-500">{{ $spec['label'] ?? $kind }}</span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="tabular-nums">{{ \App\Support\Formatting::date($until) }}</span>
                                <button type="button"
                                        wire:click="unsnoozeSignal('{{ $kind }}')"
                                        class="text-neutral-500 hover:text-neutral-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    {{ __('wake') }}
                                </button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</div>
