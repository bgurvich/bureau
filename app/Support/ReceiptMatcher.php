<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Auto-pairs OCR-extracted receipt Media with existing outflow Transactions.
 * Runs per Media with a populated `ocr_extracted.amount` + `issued_on`;
 * looks for Transactions in the same household where:
 *   - transaction.amount is the negative of the receipt amount (receipt
 *     amount is always positive; the user's spending is negative in Bureau's
 *     ledger convention),
 *   - transaction.occurred_on is within ±3 days of the receipt's issued_on,
 *   - the transaction doesn't already have a receipt-role media attached.
 *
 * On a single unambiguous hit, attaches the Media to the Transaction via
 * the existing HasMedia pivot with role='receipt' and marks the Media
 * processed. Multiple hits → leaves the Media alone for the user to pick.
 *
 * The caller gates by household scope (Media + Transaction both use the
 * BelongsToHousehold global scope), so nothing here needs to know about
 * households directly.
 */
class ReceiptMatcher
{
    public const MATCH_SINGLE = 'matched';

    public const MATCH_AMBIGUOUS = 'ambiguous';

    public const MATCH_NONE = 'no-match';

    public const MATCH_SKIP = 'skip';

    public function __construct(private readonly int $toleranceDays = 3) {}

    /**
     * Returns one of the MATCH_* constants.
     */
    public function match(Media $media): string
    {
        $extracted = is_array($media->ocr_extracted) ? $media->ocr_extracted : [];
        $amount = $extracted['amount'] ?? null;
        $issuedOn = $extracted['issued_on'] ?? null;

        if (! is_numeric($amount) || $amount <= 0) {
            return self::MATCH_SKIP;
        }
        try {
            $date = $issuedOn ? CarbonImmutable::parse((string) $issuedOn) : null;
        } catch (\Throwable) {
            $date = null;
        }
        if ($date === null) {
            return self::MATCH_SKIP;
        }

        // Receipt amount is positive; the user's spending hits the account
        // as a negative. Match both signs (debit cards emit negative; cash
        // from an `asset` account may be positive-from-account-to-expense in
        // older ledger conventions the user might have).
        $wanted = -abs((float) $amount);
        $from = $date->subDays($this->toleranceDays)->toDateString();
        $to = $date->addDays($this->toleranceDays)->toDateString();

        $candidates = Transaction::query()
            ->whereBetween('occurred_on', [$from, $to])
            ->where('amount', $wanted)
            // `wherePivot()` only works on a BelongsToMany instance, not inside
            // a whereDoesntHave subquery. Qualify by pivot column instead.
            ->whereDoesntHave('media', fn ($q) => $q->where('mediables.role', 'receipt'))
            ->limit(5)
            ->get();

        if ($candidates->count() === 0) {
            return self::MATCH_NONE;
        }
        if ($candidates->count() > 1) {
            return self::MATCH_AMBIGUOUS;
        }

        /** @var Transaction $transaction */
        $transaction = $candidates->first();
        $transaction->media()->syncWithoutDetaching([
            $media->id => ['role' => 'receipt'],
        ]);
        $media->forceFill(['processed_at' => now()])->save();

        return self::MATCH_SINGLE;
    }
}
