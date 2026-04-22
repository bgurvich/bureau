<?php

use App\Models\TaxYear;
use App\Support\CurrentHousehold;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Taxes'])]
class extends Component
{
    #[On('inspector-saved')]
    public function refresh(): void
    {
        unset($this->years);
    }

    /** @return Collection<int, TaxYear> */
    #[Computed]
    public function years(): Collection
    {
        /** @var Collection<int, TaxYear> $list */
        $list = TaxYear::query()
            ->with([
                'documents:id,tax_year_id,kind,received_on,amount,currency,from_contact_id',
                'documents.fromContact:id,display_name',
                'estimatedPayments:id,tax_year_id,quarter,due_on,paid_on,amount,currency',
            ])
            ->orderByDesc('year')
            ->orderBy('jurisdiction')
            ->get();

        return $list;
    }

    #[Computed]
    public function currency(): string
    {
        return CurrentHousehold::get()?->default_currency ?? 'USD';
    }

    /** Launch the Add-document inspector prefilled with this year. */
    public function addDocument(int $taxYearId): void
    {
        $this->dispatch('inspector-open', type: 'tax_document', parentId: $taxYearId);
    }

    /** Launch the Add-payment inspector prefilled with this year. */
    public function addPayment(int $taxYearId): void
    {
        $this->dispatch('inspector-open', type: 'tax_estimated_payment', parentId: $taxYearId);
    }
};
?>

<div class="space-y-5">
    <x-ui.page-header
        :title="__('Taxes')"
        :description="__('One row per tax year — your W-2/1099 checklist, estimated payments, and filing status.')">
        <x-ui.new-record-button type="tax_year" :label="__('New tax year')" />
    </x-ui.page-header>

    @if ($this->years->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('No tax years on file yet.') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->years as $ty)
                @php
                    $docCount = $ty->documents->count();
                    $paid = $ty->estimatedPayments->whereNotNull('paid_on')->count();
                    $due = $ty->estimatedPayments->count();
                    $filedBadgeClass = match ($ty->state) {
                        'filed' => 'border-emerald-700 text-emerald-300',
                        'amended' => 'border-amber-700 text-amber-300',
                        'extended' => 'border-sky-700 text-sky-300',
                        default => 'border-neutral-700 text-neutral-300',
                    };
                @endphp
                <section class="rounded-xl border border-neutral-800 bg-neutral-900/40">
                    <header class="flex flex-wrap items-baseline justify-between gap-3 border-b border-neutral-800 px-4 py-3">
                        <x-ui.inspector-row type="tax_year" :id="$ty->id" class="flex items-baseline gap-3">
                            <h3 class="text-sm font-semibold tabular-nums text-neutral-100">{{ $ty->year }}</h3>
                            <span class="rounded border px-1.5 py-0.5 text-[10px] uppercase tracking-wider {{ $filedBadgeClass }}">{{ App\Support\Enums::taxYearStates()[$ty->state] ?? $ty->state }}</span>
                            <span class="text-sm text-neutral-500">{{ $ty->jurisdiction }}</span>
                            @if ($ty->filing_status)
                                <span class="text-sm text-neutral-500">{{ App\Support\Enums::taxFilingStatuses()[$ty->filing_status] ?? $ty->filing_status }}</span>
                            @endif
                            @if ($ty->filed_on)
                                <span class="text-sm text-neutral-500">{{ __('filed') }} {{ Formatting::date($ty->filed_on) }}</span>
                            @endif
                        </x-ui.inspector-row>
                        @if ($ty->settlement_amount !== null)
                            @php
                                $settlementPositive = (float) $ty->settlement_amount >= 0;
                                $settlementSign = $settlementPositive ? '+' : '';
                                $settlementClass = $settlementPositive ? 'text-emerald-400' : 'text-rose-400';
                            @endphp
                            <span class="tabular-nums text-sm {{ $settlementClass }}">
                                {{ $settlementSign }}{{ Formatting::money((float) $ty->settlement_amount, $ty->currency ?? $this->currency) }}
                            </span>
                        @endif
                    </header>

                    <div class="grid gap-4 p-4 md:grid-cols-2">
                        <div>
                            <div class="mb-2 flex items-baseline justify-between">
                                <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                                    {{ __('Documents') }}
                                    <span class="ml-1 text-neutral-600">· {{ $docCount }}</span>
                                </h4>
                                <button type="button" wire:click="addDocument({{ $ty->id }})"
                                        class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[11px] text-neutral-300 hover:border-neutral-500 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    + {{ __('Add') }}
                                </button>
                            </div>
                            @if ($docCount === 0)
                                <p class="text-sm text-neutral-600">{{ __('No documents logged.') }}</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($ty->documents->sortBy('kind') as $d)
                                        <x-ui.inspector-row type="tax_document" :id="$d->id" class="flex items-baseline justify-between rounded-md px-2 py-1 text-sm hover:bg-neutral-800/40">
                                            <div class="min-w-0 flex-1">
                                                <span class="font-mono text-sm text-neutral-100">{{ $d->kind }}</span>
                                                @if ($d->fromContact)
                                                    <span class="ml-2 text-sm text-neutral-500">{{ $d->fromContact->display_name }}</span>
                                                @endif
                                                @if ($d->received_on)
                                                    <span class="ml-2 text-sm text-neutral-500">{{ Formatting::date($d->received_on) }}</span>
                                                @endif
                                            </div>
                                            @if ($d->amount !== null)
                                                <span class="tabular-nums text-sm text-neutral-300">{{ Formatting::money((float) $d->amount, $d->currency ?? $this->currency) }}</span>
                                            @endif
                                        </x-ui.inspector-row>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div>
                            <div class="mb-2 flex items-baseline justify-between">
                                <h4 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                                    {{ __('Estimated payments') }}
                                    @if ($due > 0)
                                        <span class="ml-1 text-neutral-600">· {{ $paid }} / {{ $due }}</span>
                                    @endif
                                </h4>
                                <button type="button" wire:click="addPayment({{ $ty->id }})"
                                        class="rounded-md border border-neutral-700 bg-neutral-900 px-2 py-0.5 text-[11px] text-neutral-300 hover:border-neutral-500 hover:text-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    + {{ __('Add') }}
                                </button>
                            </div>
                            @if ($due === 0)
                                <p class="text-sm text-neutral-600">{{ __('No quarterly payments scheduled.') }}</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($ty->estimatedPayments->sortBy('due_on') as $p)
                                        @php
                                            $isPaid = $p->paid_on !== null;
                                            $isOverdue = ! $isPaid && $p->due_on && $p->due_on->isPast();
                                            $rowClass = match (true) {
                                                $isPaid => 'text-neutral-500',
                                                $isOverdue => 'text-rose-400',
                                                default => 'text-neutral-200',
                                            };
                                        @endphp
                                        <x-ui.inspector-row type="tax_estimated_payment" :id="$p->id" class="flex items-baseline justify-between rounded-md px-2 py-1 text-sm hover:bg-neutral-800/40 {{ $rowClass }}">
                                            <div>
                                                <span class="font-mono text-sm">{{ $p->quarter }}</span>
                                                <span class="ml-2 text-sm">{{ Formatting::date($p->due_on) }}</span>
                                                @if ($isPaid)
                                                    <span class="ml-2 text-[11px] text-emerald-400">{{ __('paid') }} {{ Formatting::date($p->paid_on) }}</span>
                                                @elseif ($isOverdue)
                                                    <span class="ml-2 text-[11px] text-rose-400">{{ __('overdue') }}</span>
                                                @endif
                                            </div>
                                            @if ($p->amount !== null)
                                                <span class="tabular-nums text-sm">{{ Formatting::money((float) $p->amount, $p->currency ?? $this->currency) }}</span>
                                            @endif
                                        </x-ui.inspector-row>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
