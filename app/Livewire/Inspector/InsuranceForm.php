<?php

declare(strict_types=1);

namespace App\Livewire\Inspector;

use App\Livewire\Inspector\Concerns\FinalizesSave;
use App\Livewire\Inspector\Concerns\HasAdminPanel;
use App\Livewire\Inspector\Concerns\HasTagList;
use App\Livewire\Inspector\Concerns\WithCounterpartyPicker;
use App\Models\Contract;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicySubject;
use App\Models\Property;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CurrentHousehold;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Extracted Insurance form. An insurance policy is a Contract
 * (kind=insurance) with an attached InsurancePolicy row carrying
 * coverage + premium + deductible + the covered subject link. Carrier
 * flows through the pivot with party_role=carrier.
 *
 * The covered-subject picker offers Vehicle | Property | self (the
 * signed-in user) and stores the choice as an `<kind>:<id>` encoded
 * string so one selector can handle three morph targets.
 */
class InsuranceForm extends Component
{
    use FinalizesSave;
    use HasAdminPanel;
    use HasTagList;
    use WithCounterpartyPicker;

    public ?int $id = null;

    public string $insurance_title = '';

    public string $insurance_coverage_kind = 'auto';

    public string $insurance_policy_number = '';

    public ?int $insurance_carrier_id = null;

    public string $insurance_starts_on = '';

    public string $insurance_ends_on = '';

    public bool $insurance_auto_renews = false;

    public string $insurance_premium_amount = '';

    public string $insurance_premium_currency = 'USD';

    public string $insurance_premium_cadence = 'monthly';

    public string $insurance_coverage_amount = '';

    public string $insurance_coverage_currency = 'USD';

    public string $insurance_deductible_amount = '';

    public string $insurance_deductible_currency = 'USD';

    public string $insurance_notes = '';

    public string $insurance_subject = '';

    public function mount(?int $id = null): void
    {
        $this->id = $id;
        $household = CurrentHousehold::get();
        $householdCurrency = $household?->default_currency ?: 'USD';

        if ($id !== null) {
            $c = Contract::with(['insurancePolicy.subjects', 'contacts'])->findOrFail($id);
            $policy = $c->insurancePolicy;

            $this->insurance_title = (string) $c->title;
            $this->insurance_starts_on = $c->starts_on ? $c->starts_on->toDateString() : '';
            $this->insurance_ends_on = $c->ends_on ? $c->ends_on->toDateString() : '';
            $this->insurance_auto_renews = (bool) $c->auto_renews;

            if ($policy !== null) {
                $this->insurance_coverage_kind = (string) $policy->coverage_kind;
                $this->insurance_policy_number = (string) ($policy->policy_number ?? '');
                $this->insurance_carrier_id = $policy->carrier_contact_id;
                $this->insurance_premium_amount = $policy->premium_amount !== null ? (string) $policy->premium_amount : '';
                $this->insurance_premium_currency = $policy->premium_currency ?: $householdCurrency;
                $this->insurance_premium_cadence = $policy->premium_cadence ?: 'monthly';
                $this->insurance_coverage_amount = $policy->coverage_amount !== null ? (string) $policy->coverage_amount : '';
                $this->insurance_coverage_currency = $policy->coverage_currency ?: $householdCurrency;
                $this->insurance_deductible_amount = $policy->deductible_amount !== null ? (string) $policy->deductible_amount : '';
                $this->insurance_deductible_currency = $policy->deductible_currency ?: $householdCurrency;
                $this->insurance_notes = (string) ($policy->notes ?? '');
            }

            $subject = $policy?->subjects->first();
            $this->insurance_subject = $subject
                ? (string) $this->encodeSubject((string) $subject->subject_type, (int) $subject->subject_id)
                : '';
            $this->loadAdminMeta();
            $this->loadTagList();
        } else {
            $this->insurance_premium_currency = $householdCurrency;
            $this->insurance_coverage_currency = $householdCurrency;
            $this->insurance_deductible_currency = $householdCurrency;
        }
    }

    #[On('inspector-save')]
    public function save(): void
    {
        $data = $this->validate([
            'insurance_title' => 'required|string|max:255',
            'insurance_coverage_kind' => ['required', Rule::in(array_keys(Enums::insuranceCoverageKinds()))],
            'insurance_policy_number' => 'nullable|string|max:128',
            'insurance_carrier_id' => 'nullable|integer|exists:contacts,id',
            'insurance_starts_on' => 'nullable|date',
            'insurance_ends_on' => 'nullable|date|after_or_equal:insurance_starts_on',
            'insurance_auto_renews' => 'boolean',
            'insurance_premium_amount' => 'nullable|numeric',
            'insurance_premium_currency' => 'nullable|string|size:3',
            'insurance_premium_cadence' => ['required', Rule::in(array_keys(Enums::insurancePremiumCadences()))],
            'insurance_coverage_amount' => 'nullable|numeric',
            'insurance_coverage_currency' => 'nullable|string|size:3',
            'insurance_deductible_amount' => 'nullable|numeric',
            'insurance_deductible_currency' => 'nullable|string|size:3',
            'insurance_notes' => 'nullable|string|max:5000',
            'insurance_subject' => 'nullable|string|max:64',
        ]);

        $divisor = Enums::cadenceToMonthlyDivisor($data['insurance_premium_cadence']);
        $monthly = ($divisor !== null && $data['insurance_premium_amount'] !== '')
            ? (float) $data['insurance_premium_amount'] / $divisor
            : null;

        $contractPayload = [
            'kind' => 'insurance',
            'title' => $data['insurance_title'],
            'starts_on' => $data['insurance_starts_on'] ?: null,
            'ends_on' => $data['insurance_ends_on'] ?: null,
            'auto_renews' => (bool) $data['insurance_auto_renews'],
            'monthly_cost_amount' => $monthly,
            'monthly_cost_currency' => $data['insurance_premium_currency'] ?: null,
            'state' => 'active',
        ];

        if ($this->id !== null) {
            $contract = tap(Contract::findOrFail($this->id))->update($contractPayload);
        } else {
            $contractPayload['primary_user_id'] = auth()->id();
            $contract = Contract::create($contractPayload);
        }

        if ($data['insurance_carrier_id']) {
            $contract->contacts()->sync([
                $data['insurance_carrier_id'] => ['party_role' => 'carrier'],
            ]);
        } else {
            $contract->contacts()->detach();
        }

        $policyPayload = [
            'contract_id' => $contract->id,
            'coverage_kind' => $data['insurance_coverage_kind'],
            'policy_number' => $data['insurance_policy_number'] ?: null,
            'carrier_contact_id' => $data['insurance_carrier_id'] ?: null,
            'premium_amount' => $data['insurance_premium_amount'] !== '' ? (float) $data['insurance_premium_amount'] : null,
            'premium_currency' => $data['insurance_premium_currency'] ?: null,
            'premium_cadence' => $data['insurance_premium_cadence'],
            'coverage_amount' => $data['insurance_coverage_amount'] !== '' ? (float) $data['insurance_coverage_amount'] : null,
            'coverage_currency' => $data['insurance_coverage_currency'] ?: null,
            'deductible_amount' => $data['insurance_deductible_amount'] !== '' ? (float) $data['insurance_deductible_amount'] : null,
            'deductible_currency' => $data['insurance_deductible_currency'] ?: null,
            'notes' => $data['insurance_notes'] ?: null,
        ];

        $policy = InsurancePolicy::updateOrCreate(
            ['contract_id' => $contract->id],
            $policyPayload,
        );

        $policy->subjects()->delete();
        if ($data['insurance_subject']) {
            [$subjectType, $subjectId] = $this->decodeSubject($data['insurance_subject']);
            if ($subjectType && $subjectId) {
                InsurancePolicySubject::create([
                    'policy_id' => $policy->id,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'role' => 'covered',
                ]);
            }
        }

        $this->id = (int) $contract->id;

        $this->persistAdminOwner();
        $this->persistTagList();

        $this->finalizeSave();
    }

    /** @return array<string, string> encoded-key ⇒ display label */
    #[Computed]
    public function insuranceSubjectOptions(): array
    {
        $options = [];
        foreach (Vehicle::orderBy('make')->get(['id', 'make', 'model', 'year']) as $v) {
            $label = trim(($v->year ? $v->year.' ' : '').(string) $v->make.' '.(string) $v->model);
            $options['vehicle:'.$v->id] = __('Vehicle').' · '.($label !== '' ? $label : __('(untitled)'));
        }
        foreach (Property::orderBy('name')->get(['id', 'name']) as $p) {
            $options['property:'.$p->id] = __('Property').' · '.$p->name;
        }
        $user = auth()->user();
        if ($user) {
            $options['user:'.$user->id] = __('Person').' · '.$user->name;
        }

        return $options;
    }

    private function encodeSubject(string $class, int $id): string
    {
        $key = match ($class) {
            Vehicle::class => 'vehicle',
            Property::class => 'property',
            User::class => 'user',
            default => 'unknown',
        };

        return $key.':'.$id;
    }

    /** @return array{0:?string,1:?int} */
    private function decodeSubject(string $encoded): array
    {
        $parts = explode(':', $encoded, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        [$key, $id] = $parts;

        $class = match ($key) {
            'vehicle' => Vehicle::class,
            'property' => Property::class,
            'user' => User::class,
            default => null,
        };

        return [$class, ctype_digit($id) ? (int) $id : null];
    }

    protected function adminOwnerClass(): ?string
    {
        return Contract::class;
    }

    protected function adminOwnerField(): ?string
    {
        return 'primary_user_id';
    }

    protected function defaultCounterpartyModelKey(): string
    {
        return 'insurance_carrier_id';
    }

    public function render(): View
    {
        return view('livewire.inspector.insurance-form');
    }
}
