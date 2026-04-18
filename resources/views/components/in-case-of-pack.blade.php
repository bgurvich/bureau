<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Document;
use App\Models\OnlineAccount;
use App\Models\Prescription;
use App\Support\Formatting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'In case of'])]
class extends Component
{
    /** @return Collection<int, Document> */
    #[Computed]
    public function documents(): Collection
    {
        return Document::where('in_case_of_pack', true)
            ->orderBy('kind')
            ->orderBy('label')
            ->get();
    }

    /** @return Collection<int, OnlineAccount> */
    #[Computed]
    public function onlineAccounts(): Collection
    {
        return OnlineAccount::with('recoveryContact:id,display_name')
            ->where(fn ($q) => $q->where('in_case_of_pack', true)->orWhere('importance_tier', 'critical'))
            ->orderBy('importance_tier')
            ->orderBy('service_name')
            ->get();
    }

    /** @return Collection<int, Contact> */
    #[Computed]
    public function favoriteContacts(): Collection
    {
        return Contact::where('favorite', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'kind', 'organization', 'phones', 'emails', 'notes']);
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::with('vendor:id,display_name')
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Prescription> */
    #[Computed]
    public function prescriptions(): Collection
    {
        return Prescription::where(fn ($q) => $q
            ->whereNull('active_to')
            ->orWhereDate('active_to', '>=', now()->toDateString())
        )
            ->orderBy('name')
            ->get();
    }
};
?>

<div class="space-y-6 print:space-y-4">
    <header class="flex items-baseline justify-between gap-4 print:mb-2">
        <div>
            <h2 class="text-base font-semibold text-neutral-100">{{ __('In case of') }}</h2>
            <p class="mt-1 text-xs text-neutral-500 print:text-[11px]">
                {{ __('Emergency-preparedness sheet. Flag records with "Include in case-of pack" in their Inspector, then print or save this page as PDF for a trusted person.') }}
            </p>
        </div>
        <button type="button" onclick="window.print()"
                class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 print:hidden">
            {{ __('Print / save PDF') }}
        </button>
    </header>

    @if ($this->favoriteContacts->isNotEmpty())
        <section aria-labelledby="ico-contacts" class="space-y-2">
            <h3 id="ico-contacts" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Favorite contacts') }}</h3>
            <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40 print:border-neutral-400">
                @foreach ($this->favoriteContacts as $c)
                    <li class="px-4 py-2 text-sm">
                        <div class="font-medium text-neutral-100">{{ $c->display_name }}</div>
                        <div class="text-[11px] text-neutral-500">{{ $c->kind }}@if ($c->organization) · {{ $c->organization }}@endif</div>
                        @if (is_array($c->phones) && count($c->phones))
                            <div class="mt-0.5 text-xs tabular-nums text-neutral-300">
                                {{ __('Phone') }}: {{ collect($c->phones)->pluck('number')->filter()->implode(' · ') }}
                            </div>
                        @endif
                        @if (is_array($c->emails) && count($c->emails))
                            <div class="text-xs text-neutral-300">
                                {{ __('Email') }}: {{ collect($c->emails)->pluck('address')->filter()->implode(' · ') }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->documents->isNotEmpty())
        <section aria-labelledby="ico-docs" class="space-y-2">
            <h3 id="ico-docs" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Documents') }}</h3>
            <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40 print:border-neutral-400">
                @foreach ($this->documents as $d)
                    <li class="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <span class="font-medium text-neutral-100">{{ $d->label ?: ucfirst((string) $d->kind) }}</span>
                            <span class="ml-2 text-[10px] uppercase tracking-wider text-neutral-500">{{ $d->kind }}</span>
                        </div>
                        <div class="text-right text-[11px] text-neutral-400">
                            @if ($d->number)<div class="font-mono">{{ $d->number }}</div>@endif
                            @if ($d->expires_on)<div class="tabular-nums">{{ __('expires') }} {{ Formatting::date($d->expires_on) }}</div>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->accounts->isNotEmpty())
        <section aria-labelledby="ico-accounts" class="space-y-2">
            <h3 id="ico-accounts" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Accounts') }}</h3>
            <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40 print:border-neutral-400">
                @foreach ($this->accounts as $a)
                    <li class="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <span class="font-medium text-neutral-100">{{ $a->name }}</span>
                            <span class="ml-2 text-[10px] uppercase tracking-wider text-neutral-500">{{ $a->type }}</span>
                        </div>
                        <div class="text-right text-[11px] text-neutral-400">
                            @if ($a->vendor)<div>{{ $a->vendor->display_name }}</div>
                            @elseif ($a->institution)<div>{{ $a->institution }}</div>@endif
                            @if ($a->external_code)<div class="font-mono tabular-nums">{{ $a->external_code }}</div>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->onlineAccounts->isNotEmpty())
        <section aria-labelledby="ico-online" class="space-y-2">
            <h3 id="ico-online" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Online accounts') }}</h3>
            <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40 print:border-neutral-400">
                @foreach ($this->onlineAccounts as $o)
                    <li class="px-4 py-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-medium text-neutral-100">{{ $o->service_name }}</span>
                            <span class="text-[10px] uppercase tracking-wider {{ $o->importance_tier === 'critical' ? 'text-rose-400' : 'text-neutral-500' }}">
                                {{ $o->importance_tier ?: $o->kind }}
                            </span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-neutral-400">
                            @if ($o->login_email){{ $o->login_email }}@endif
                            @if ($o->mfa_method) · {{ __('2FA') }}: {{ $o->mfa_method }}@endif
                            @if ($o->recoveryContact) · {{ __('recovery') }}: {{ $o->recoveryContact->display_name }}@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($this->prescriptions->isNotEmpty())
        <section aria-labelledby="ico-rx" class="space-y-2">
            <h3 id="ico-rx" class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">{{ __('Active prescriptions') }}</h3>
            <ul class="divide-y divide-neutral-800 rounded-xl border border-neutral-800 bg-neutral-900/40 print:border-neutral-400">
                @foreach ($this->prescriptions as $rx)
                    <li class="flex items-baseline justify-between gap-3 px-4 py-2 text-sm">
                        <div>
                            <span class="font-medium text-neutral-100">{{ $rx->name }}</span>
                            @if ($rx->dosage)<span class="ml-2 text-[11px] text-neutral-400">{{ $rx->dosage }}</span>@endif
                        </div>
                        @if ($rx->schedule)<div class="text-[11px] text-neutral-400">{{ $rx->schedule }}</div>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (
        $this->documents->isEmpty() &&
        $this->onlineAccounts->isEmpty() &&
        $this->favoriteContacts->isEmpty() &&
        $this->accounts->isEmpty() &&
        $this->prescriptions->isEmpty()
    )
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('Nothing flagged yet. Mark documents, online accounts, or contacts as in-case-of-pack / favorite to build this sheet.') }}
        </div>
    @endif
</div>

@push('head')
    <style media="print">
        @page { margin: 12mm; }
        body { background: white !important; color: black !important; }
        .print\:hidden { display: none !important; }
    </style>
@endpush
