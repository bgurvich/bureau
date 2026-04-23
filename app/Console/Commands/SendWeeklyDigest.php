<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\Contract;
use App\Models\Household;
use App\Models\RecurringProjection;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "this week at a glance" digest to every user who has access
 * to at least one household. Queries the last 7 days for activity and the
 * next 7 days for upcoming items, then rolls up into a single mail per
 * user (not per household — a user in two households still gets one mail,
 * with rollups aggregated across households).
 *
 * Scheduled Sunday 17:00 local. `--dry-run` prints without sending.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:weekly {--dry-run}';

    protected $description = 'Mail each user a weekly "what changed" summary';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $windowStart = CarbonImmutable::now()->subDays(7)->startOfDay();
        $windowEnd = CarbonImmutable::now()->endOfDay();
        $nextStart = CarbonImmutable::now()->startOfDay();
        $nextEnd = CarbonImmutable::now()->addDays(7)->endOfDay();

        $sent = 0;
        foreach (User::whereHas('households')->with('households')->get() as $user) {
            $payload = $this->buildPayload($user, $windowStart, $windowEnd, $nextStart, $nextEnd);
            if ($payload === null) {
                continue;
            }
            if ($dryRun) {
                $this->line("  would send → {$user->email} (new={$payload['new_transactions_count']}, tasks={$payload['completed_tasks_count']})");
                $sent++;

                continue;
            }
            Mail::to($user->email)->send(new WeeklyDigestMail($user, $payload));
            $sent++;
        }

        $this->info("  Sent {$sent} digest(s)".($dryRun ? ' (dry run)' : '').'.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPayload(User $user, CarbonImmutable $ws, CarbonImmutable $we, CarbonImmutable $ns, CarbonImmutable $ne): ?array
    {
        $householdIds = $user->households->pluck('id')->all();
        if ($householdIds === []) {
            return null;
        }

        // Activating any single household gives us the correct global scope
        // for these queries — but we actually want *all* of the user's
        // households, so we bypass the scope explicitly.
        $newTxns = Transaction::withoutGlobalScopes()
            ->whereHas('account', fn ($q) => $q->whereIn('household_id', $householdIds))
            ->whereBetween('created_at', [$ws, $we])
            ->get(['amount']);

        $completedTasks = Task::withoutGlobalScopes()
            ->whereIn('household_id', $householdIds)
            ->where('state', 'done')
            ->whereBetween('updated_at', [$ws, $we])
            ->count();

        $upcomingTasks = Task::withoutGlobalScopes()
            ->whereIn('household_id', $householdIds)
            ->whereIn('state', ['open', 'waiting'])
            ->whereBetween('due_at', [$ns, $ne])
            ->count();

        $upcomingBills = RecurringProjection::withoutGlobalScopes()
            ->whereHas('rule', fn ($q) => $q->whereIn('household_id', $householdIds))
            ->whereIn('status', ['projected', 'overdue'])
            ->whereBetween('due_on', [$ns->toDateString(), $ne->toDateString()])
            ->get(['amount']);

        $expiring = Contract::withoutGlobalScopes()
            ->whereIn('household_id', $householdIds)
            ->where('auto_renews', true)
            ->whereNotNull('ends_on')
            ->whereBetween('ends_on', [$ns->toDateString(), $ns->addDays(14)->toDateString()])
            ->where(fn ($q) => $q->whereNotNull('cancellation_url')->orWhereNotNull('cancellation_email'))
            ->get(['title', 'ends_on', 'cancellation_url', 'cancellation_email']);

        $subscriptions = Subscription::withoutGlobalScopes()
            ->whereIn('household_id', $householdIds)
            ->where('state', 'active')
            ->get(['monthly_cost_cached']);
        // Unknown-cadence entries skipped from the total (better dash than lie,
        // but the digest just surfaces a total — we omit unknowns silently
        // and reflect the shortfall in the count vs total).
        // Storage is signed (outflows negative); the digest shows a
        // subscription-spend magnitude, so take the absolute value here.
        $subscriptionsMonthly = 0.0;
        foreach ($subscriptions as $s) {
            if ($s->monthly_cost_cached !== null) {
                $subscriptionsMonthly += abs((float) $s->monthly_cost_cached);
            }
        }

        $currency = 'USD';
        $current = CurrentHousehold::get();
        if ($current instanceof Household && ! empty($current->default_currency)) {
            $currency = (string) $current->default_currency;
        } else {
            /** @var Household|null $defaultHousehold */
            $defaultHousehold = $user->defaultHousehold;
            if ($defaultHousehold instanceof Household && ! empty($defaultHousehold->default_currency)) {
                $currency = (string) $defaultHousehold->default_currency;
            }
        }

        return [
            'window_start' => $ws->toDateString(),
            'window_end' => $we->toDateString(),
            'new_transactions_count' => $newTxns->count(),
            'new_transactions_net' => (float) $newTxns->sum('amount'),
            'completed_tasks_count' => $completedTasks,
            'upcoming_tasks_count' => $upcomingTasks,
            'upcoming_bills_count' => $upcomingBills->count(),
            'upcoming_bills_total' => (float) abs($upcomingBills->sum('amount')),
            'expiring_contracts_count' => $expiring->count(),
            'active_subscriptions_count' => $subscriptions->count(),
            'active_subscriptions_monthly' => $subscriptionsMonthly,
            'expiring_contracts' => $expiring->map(fn ($c) => [
                'title' => $c->title,
                'ends_on' => $c->ends_on?->toDateString() ?? '',
                'cancellation_url' => $c->cancellation_url,
                'cancellation_email' => $c->cancellation_email,
            ])->all(),
            'currency' => $currency,
        ];
    }
}
