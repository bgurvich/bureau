<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\RecurringRule;
use App\Models\Subscription;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use App\Support\SubscriptionSync;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Subscription form. Not in adminModelMap, so no admin panel.
 * Owns its three picker computeds (counterparties, recurring rules,
 * open contracts) so the searchable-selects resolve $this->… against
 * the child rather than the shell.
 */
class SubscriptionForm extends Component
{
    use FinalizesSave;

    public ?int $id = null;

    public string $subscription_name = '';

    public ?int $subscription_counterparty_id = null;

    public ?int $subscription_recurring_rule_id = null;

    public ?int $subscription_contract_id = null;

    public string $subscription_state = 'active';

    public string $subscription_paused_until = '';

    public string $subscription_currency = 'USD';

    public string $subscription_notes = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        if ($id !== null) {
            $s = Subscription::findOrFail($id);
            $this->subscription_name = (string) $s->name;
            $this->subscription_counterparty_id = $s->counterparty_contact_id;
            $this->subscription_recurring_rule_id = $s->recurring_rule_id;
            $this->subscription_contract_id = $s->contract_id;
            $this->subscription_state = (string) $s->state;
            $this->subscription_paused_until = $s->paused_until ? $s->paused_until->toDateString() : '';
            $this->subscription_currency = (string) $s->currency;
            $this->subscription_notes = (string) ($s->notes ?? '');
        } else {
            $household = CurrentHousehold::get();
            $this->subscription_currency = $household?->default_currency ?: 'USD';
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'subscription_name' => 'required|string|max:120',
            'subscription_counterparty_id' => 'nullable|integer|exists:contacts,id',
            'subscription_recurring_rule_id' => 'nullable|integer|exists:recurring_rules,id',
            'subscription_contract_id' => 'nullable|integer|exists:contracts,id',
            'subscription_state' => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'subscription_paused_until' => 'nullable|date',
            'subscription_currency' => 'required|string|size:3|alpha',
            'subscription_notes' => 'nullable|string|max:5000',
        ]);

        $monthly = null;
        if ($data['subscription_recurring_rule_id']) {
            $rule = RecurringRule::find($data['subscription_recurring_rule_id']);
            if ($rule) {
                $multiplier = SubscriptionSync::monthlyMultiplier((string) $rule->rrule);
                // Signed — outflows come through as negative, matching
                // the rule's convention (and SubscriptionSync).
                $monthly = $multiplier !== null ? $multiplier * (float) $rule->amount : null;
            }
        }

        $payload = [
            'name' => $data['subscription_name'],
            'counterparty_contact_id' => $data['subscription_counterparty_id'] ?: null,
            'recurring_rule_id' => $data['subscription_recurring_rule_id'] ?: null,
            'contract_id' => $data['subscription_contract_id'] ?: null,
            'state' => $data['subscription_state'],
            'paused_until' => $data['subscription_state'] === 'paused'
                ? ($data['subscription_paused_until'] ?: null)
                : null,
            'currency' => strtoupper($data['subscription_currency']),
            'notes' => $data['subscription_notes'] ?: null,
            'monthly_cost_cached' => $monthly,
            'last_seen_at' => now(),
        ];

        if ($this->id !== null) {
            Subscription::findOrFail($this->id)->forceFill($payload)->save();
        } else {
            $payload['first_seen_at'] = now();
            $this->id = (int) Subscription::forceCreate($payload)->id;
        }

        $this->finalizeSave();
    }

    /** @return array<int, string> */
    #[Computed]
    public function counterpartyPickerOptions(): array
    {
        return Contact::orderBy('display_name')->pluck('display_name', 'id')->all();
    }

    public function createCounterparty(string $name, ?string $modelKey = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $contact = Contact::create([
            'kind' => 'org',
            'display_name' => $name,
        ]);

        $targetKey = $modelKey && property_exists($this, $modelKey)
            ? $modelKey
            : 'subscription_counterparty_id';
        $this->{$targetKey} = $contact->id;
        unset($this->counterpartyPickerOptions);

        $this->dispatch('ss-option-added',
            model: $targetKey,
            id: $contact->id,
            label: $contact->display_name,
        );
    }

    /** @return array<int, string> */
    #[Computed]
    public function recurringOutflowRulePickerOptions(): array
    {
        return RecurringRule::where('active', true)
            ->where('amount', '<=', 0)
            ->orderBy('title')
            ->get(['id', 'title', 'amount', 'currency'])
            ->mapWithKeys(fn ($r) => [
                $r->id => $r->title.' · '.Formatting::money(abs((float) $r->amount), $r->currency ?? 'USD'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    #[Computed]
    public function openContractPickerOptions(): array
    {
        return Contract::whereNotIn('state', ['ended', 'cancelled'])
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    public function render(): View
    {
        return view('livewire.inspector.subscription-form');
    }
}
