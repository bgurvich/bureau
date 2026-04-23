<?php

use App\Models\Account;
use App\Models\ChecklistRun;
use App\Models\Contract;
use App\Models\Decision;
use App\Models\Domain;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\PetCheckup;
use App\Models\PetLicense;
use App\Models\PetVaccination;
use App\Models\RecurringProjection;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Task;
use App\Models\TaxEstimatedPayment;
use App\Models\Transaction;
use App\Support\BudgetMonitor;
use App\Support\ChecklistScheduling;
use App\Support\SpendingAnomalyDetector;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
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

    #[Computed]
    public function total(): int
    {
        return $this->overdueTasks
            + $this->unreconciled
            + $this->overdueBills
            + $this->pendingReminders
            + $this->trialsEndingSoon
            + $this->autorenewingContractsEndingSoon
            + $this->giftCardsExpiringSoon
            + $this->domainsExpiringSoon
            + $this->taxPaymentsDueSoon
            + $this->billsInbox
            + $this->unprocessedInventory
            + $this->budgetEnvelopesAtRisk
            + $this->spendingAnomalies
            + $this->savingsGoalsReadyToClose
            + $this->unfinishedMorningRituals
            + $this->unfinishedEveningRituals
            + $this->expiringPetVaccinations
            + $this->overduePetCheckups
            + $this->expiringPetLicenses
            + $this->decisionFollowUpsDue;
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Attention</h3>
        <span class="text-xs tabular-nums {{ $this->total > 0 ? 'text-amber-400' : 'text-neutral-500' }}">
            {{ $this->total }} {{ $this->total === 1 ? 'item' : 'items' }}
        </span>
    </div>

    @if ($this->total === 0)
        <div class="py-6 text-center text-xs text-neutral-600">Nothing is waiting on you.</div>
    @else
        <ul class="space-y-2 text-sm">
            @if ($this->overdueTasks)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Overdue tasks</span>
                    <span class="tabular-nums text-amber-400">{{ $this->overdueTasks }}</span>
                </li>
            @endif
            @if ($this->overdueBills)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Overdue bills</span>
                    <span class="tabular-nums text-rose-400">{{ $this->overdueBills }}</span>
                </li>
            @endif
            @if ($this->unreconciled)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Unreconciled transactions</span>
                    <span class="tabular-nums text-neutral-400">{{ $this->unreconciled }}</span>
                </li>
            @endif
            @if ($this->pendingReminders)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Due reminders</span>
                    <span class="tabular-nums text-amber-400">{{ $this->pendingReminders }}</span>
                </li>
            @endif
            @if ($this->trialsEndingSoon)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Trials ending ≤ 7d</span>
                    <span class="tabular-nums text-rose-400">{{ $this->trialsEndingSoon }}</span>
                </li>
            @endif
            @if ($this->autorenewingContractsEndingSoon)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('relationships.contracts') }}" class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Auto-renewing ≤ 14d') }}</a>
                    <span class="tabular-nums text-amber-400">{{ $this->autorenewingContractsEndingSoon }}</span>
                </li>
            @endif
            @if ($this->giftCardsExpiringSoon)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">Gift cards expiring ≤ 30d</span>
                    <span class="tabular-nums text-amber-400">{{ $this->giftCardsExpiringSoon }}</span>
                </li>
            @endif
            @if ($this->domainsExpiringSoon)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('assets.domains', ['status' => 'expiring']) }}" class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Domains expiring ≤ 30d') }}</a>
                    <span class="tabular-nums text-amber-400">{{ $this->domainsExpiringSoon }}</span>
                </li>
            @endif
            @if ($this->taxPaymentsDueSoon)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('fiscal.tax') }}" class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Estimated tax due ≤ 30d') }}</a>
                    <span class="tabular-nums text-rose-400">{{ $this->taxPaymentsDueSoon }}</span>
                </li>
            @endif
            @if ($this->billsInbox)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('fiscal.inbox') }}" class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Bills Inbox') }}</a>
                    <span class="tabular-nums text-sky-300">{{ $this->billsInbox }}</span>
                </li>
            @endif
            @if ($this->unprocessedInventory)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('assets.inventory', ['status' => 'unprocessed']) }}" class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">{{ __('Unprocessed inventory') }}</a>
                    <span class="tabular-nums text-amber-400">{{ $this->unprocessedInventory }}</span>
                </li>
            @endif
            @if ($this->budgetEnvelopesAtRisk)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('fiscal.budgets') }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Envelopes ≥ 80% used') }}
                    </a>
                    <span class="tabular-nums text-rose-400">{{ $this->budgetEnvelopesAtRisk }}</span>
                </li>
            @endif
            @if ($this->spendingAnomalies)
                <li class="flex items-baseline justify-between">
                    <span class="text-neutral-300">{{ __('Unusual charges ≤ 7d') }}</span>
                    <span class="tabular-nums text-amber-400">{{ $this->spendingAnomalies }}</span>
                </li>
            @endif
            @if ($this->savingsGoalsReadyToClose)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('fiscal.savings_goals') }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Savings goals hit target') }}
                    </a>
                    <span class="tabular-nums text-emerald-400">{{ $this->savingsGoalsReadyToClose }}</span>
                </li>
            @endif
            @if ($this->unfinishedMorningRituals)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('life.checklists.today') }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Unfinished morning routine') }}
                    </a>
                    <span class="tabular-nums text-amber-400">{{ $this->unfinishedMorningRituals }}</span>
                </li>
            @endif
            @if ($this->unfinishedEveningRituals)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('life.checklists.today') }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Unfinished evening routine') }}
                    </a>
                    <span class="tabular-nums text-amber-400">{{ $this->unfinishedEveningRituals }}</span>
                </li>
            @endif
            @if ($this->expiringPetVaccinations)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('pets.index', ['tab' => 'vaccinations']) }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Pet vaccines expiring / expired') }}
                    </a>
                    <span class="tabular-nums text-amber-400">{{ $this->expiringPetVaccinations }}</span>
                </li>
            @endif
            @if ($this->overduePetCheckups)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('pets.index', ['tab' => 'checkups']) }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Overdue pet checkups') }}
                    </a>
                    <span class="tabular-nums text-rose-400">{{ $this->overduePetCheckups }}</span>
                </li>
            @endif
            @if ($this->expiringPetLicenses)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('pets.index', ['tab' => 'licenses', 'status' => 'expiring']) }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Pet licenses expiring ≤ 30d') }}
                    </a>
                    <span class="tabular-nums text-amber-400">{{ $this->expiringPetLicenses }}</span>
                </li>
            @endif
            @if ($this->decisionFollowUpsDue)
                <li class="flex items-baseline justify-between">
                    <a href="{{ route('life.decisions', ['status' => 'awaiting_followup']) }}"
                       class="text-neutral-300 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Decisions awaiting follow-up') }}
                    </a>
                    <span class="tabular-nums text-amber-400">{{ $this->decisionFollowUpsDue }}</span>
                </li>
            @endif
        </ul>
    @endif
</div>
