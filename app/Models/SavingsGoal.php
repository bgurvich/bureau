<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoal extends Model
{
    use BelongsToHousehold;

    protected $guarded = [];

    protected $casts = [
        'target_amount' => 'decimal:4',
        'starting_amount' => 'decimal:4',
        'saved_amount' => 'decimal:4',
        'target_date' => 'date',
        'milestones_hit' => 'array',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * When an Account is linked, the saved amount follows its latest balance
     * minus the snapshot we took when the goal was created (starting_amount).
     * Otherwise returns the manually-set saved_amount.
     */
    public function currentSaved(): float
    {
        if ($this->account_id && $this->account) {
            /** @var AccountBalance|null $latest */
            $latest = $this->account->latestBalance;
            if ($latest) {
                return max(0.0, (float) $latest->balance - (float) $this->starting_amount);
            }
        }

        return (float) $this->saved_amount;
    }

    public function progressRatio(): float
    {
        $target = (float) $this->target_amount;
        if ($target <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->currentSaved() / $target));
    }
}
