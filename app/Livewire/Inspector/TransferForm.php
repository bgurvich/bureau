<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Transfer form. Create-only in v1: editing a transfer would
 * force rewriting both mirror transactions, so the inspector only builds
 * new ones. Each side can either link an existing unpaired Transaction
 * (picked from the computed outflow/inflow pickers) or synthesize a new
 * mirror row. `validatePickedTransferTransactions` keeps picks honest
 * (correct account, correct sign, not already wired to another transfer).
 */
class TransferForm extends Component
{
    public ?int $id = null;

    public string $transfer_occurred_on = '';

    public ?int $transfer_from_account_id = null;

    public ?int $transfer_to_account_id = null;

    public string $transfer_amount = '';

    public string $transfer_currency = 'USD';

    public string $transfer_description = '';

    public ?int $transfer_from_transaction_id = null;

    public ?int $transfer_to_transaction_id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id === null) {
            $this->transfer_occurred_on = now()->toDateString();
            $household = CurrentHousehold::get();
            $this->transfer_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'transfer_occurred_on' => 'required|date',
            'transfer_from_account_id' => 'required|integer|exists:accounts,id|different:transfer_to_account_id',
            'transfer_to_account_id' => 'required|integer|exists:accounts,id',
            'transfer_amount' => 'required|numeric|min:0.01',
            'transfer_currency' => 'required|string|size:3|alpha',
            'transfer_description' => 'nullable|string|max:500',
            'transfer_from_transaction_id' => 'nullable|integer|exists:transactions,id',
            'transfer_to_transaction_id' => 'nullable|integer|exists:transactions,id',
        ]);

        if ($this->id) {
            return;
        }

        $household = CurrentHousehold::get();
        abort_unless($household !== null, 403);

        $amount = abs((float) $data['transfer_amount']);
        $currency = strtoupper($data['transfer_currency']);
        $description = $data['transfer_description'] ?: __('Transfer');
        $fromAccountId = (int) $data['transfer_from_account_id'];
        $toAccountId = (int) $data['transfer_to_account_id'];

        $fromTxn = $data['transfer_from_transaction_id']
            ? Transaction::find($data['transfer_from_transaction_id'])
            : null;
        $toTxn = $data['transfer_to_transaction_id']
            ? Transaction::find($data['transfer_to_transaction_id'])
            : null;

        $pickedError = $this->validatePickedTransferTransactions(
            $fromTxn, $toTxn, $fromAccountId, $toAccountId
        );
        if ($pickedError !== null) {
            throw ValidationException::withMessages([
                'transfer_from_transaction_id' => $pickedError,
            ]);
        }

        DB::transaction(function () use (
            $household, $data, $amount, $currency, $description,
            $fromAccountId, $toAccountId, &$fromTxn, &$toTxn
        ) {
            if (! $fromTxn) {
                $fromTxn = Transaction::create([
                    'household_id' => $household->id,
                    'account_id' => $fromAccountId,
                    'occurred_on' => $data['transfer_occurred_on'],
                    'amount' => -$amount,
                    'currency' => $currency,
                    'description' => $description,
                    'status' => 'cleared',
                    'import_source' => 'manual:transfer',
                    'reconciled_at' => now(),
                ]);
            }

            if (! $toTxn) {
                $toTxn = Transaction::create([
                    'household_id' => $household->id,
                    'account_id' => $toAccountId,
                    'occurred_on' => $data['transfer_occurred_on'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'status' => 'cleared',
                    'import_source' => 'manual:transfer',
                    'reconciled_at' => now(),
                ]);
            }

            $transfer = Transfer::create([
                'household_id' => $household->id,
                'occurred_on' => $data['transfer_occurred_on'],
                'from_account_id' => $fromTxn->account_id,
                'from_amount' => $fromTxn->amount,
                'from_currency' => $fromTxn->currency,
                'from_transaction_id' => $fromTxn->id,
                'to_account_id' => $toTxn->account_id,
                'to_amount' => $toTxn->amount,
                'to_currency' => $toTxn->currency,
                'to_transaction_id' => $toTxn->id,
                'description' => $description,
                'status' => 'cleared',
            ]);

            $this->id = $transfer->id;
        });

        $this->dispatch('inspector-saved', type: 'transfer', id: $this->id);
        $this->dispatch('inspector-form-saved', type: 'transfer', id: $this->id);
    }

    private function validatePickedTransferTransactions(
        ?Transaction $fromTxn,
        ?Transaction $toTxn,
        int $fromAccountId,
        int $toAccountId,
    ): ?string {
        $alreadyPaired = function (Transaction $t): bool {
            return Transfer::query()
                ->where(function ($q) use ($t) {
                    $q->where('from_transaction_id', $t->id)
                        ->orWhere('to_transaction_id', $t->id);
                })->exists();
        };

        if ($fromTxn) {
            if ($fromTxn->account_id !== $fromAccountId) {
                return __('The picked outflow transaction is in a different account.');
            }
            if ((float) $fromTxn->amount >= 0) {
                return __('The picked outflow transaction must be a debit.');
            }
            if ($alreadyPaired($fromTxn)) {
                return __('That outflow transaction is already part of another transfer.');
            }
        }

        if ($toTxn) {
            if ($toTxn->account_id !== $toAccountId) {
                return __('The picked inflow transaction is in a different account.');
            }
            if ((float) $toTxn->amount <= 0) {
                return __('The picked inflow transaction must be a credit.');
            }
            if ($alreadyPaired($toTxn)) {
                return __('That inflow transaction is already part of another transfer.');
            }
        }

        return null;
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::orderBy('name')->get(['id', 'name', 'currency']);
    }

    /** @return array<int, string> */
    #[Computed]
    public function transferOutflowPickerOptions(): array
    {
        return $this->unpairedTransferCandidates('outflow', $this->transfer_from_account_id);
    }

    /** @return array<int, string> */
    #[Computed]
    public function transferInflowPickerOptions(): array
    {
        return $this->unpairedTransferCandidates('inflow', $this->transfer_to_account_id);
    }

    /** @return array<int, string> */
    private function unpairedTransferCandidates(string $direction, ?int $accountId): array
    {
        $pairedIds = Transfer::query()
            ->whereNotNull('from_transaction_id')->pluck('from_transaction_id')
            ->merge(Transfer::query()->whereNotNull('to_transaction_id')->pluck('to_transaction_id'))
            ->unique()
            ->all();

        $query = Transaction::query()
            ->whereNotIn('id', $pairedIds)
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId));

        if ($direction === 'outflow') {
            $query->where('amount', '<', 0);
        } else {
            $query->where('amount', '>', 0);
        }

        return $query
            ->orderByDesc('occurred_on')
            ->limit(100)
            ->with('account:id,name')
            ->get(['id', 'account_id', 'occurred_on', 'amount', 'currency', 'description'])
            ->mapWithKeys(function ($t) {
                $date = $t->occurred_on ? $t->occurred_on->toDateString() : '—';
                $account = $t->account ? (string) $t->account->name : '—';
                $amount = Formatting::money(abs((float) $t->amount), $t->currency ?? 'USD');
                $desc = (string) ($t->description ?? '');
                $label = trim($date.' · '.$account.' · '.$amount.($desc !== '' ? ' · '.Str::limit($desc, 40) : ''));

                return [$t->id => $label];
            })
            ->all();
    }

    public function render(): View
    {
        return view('livewire.inspector.transfer-form');
    }
}
