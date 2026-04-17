<?php

use App\Models\Document;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function total(): int
    {
        return Document::count();
    }

    #[Computed]
    public function expiring90(): int
    {
        return Document::whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(90)->toDateString()])
            ->count();
    }

    #[Computed]
    public function soonest(): Collection
    {
        return Document::whereNotNull('expires_on')
            ->where('expires_on', '>=', now()->toDateString())
            ->orderBy('expires_on')
            ->limit(3)
            ->get();
    }
};
?>

<div class="rounded-xl border border-neutral-800 bg-neutral-900/50 p-5">
    <div class="mb-4 flex items-baseline justify-between">
        <h3 class="text-xs font-medium uppercase tracking-wider text-neutral-500">Documents</h3>
        <a href="{{ route('records.documents') }}" class="text-xs text-neutral-500 hover:text-neutral-300">All →</a>
    </div>

    <div class="mb-4 flex items-baseline gap-6">
        <div>
            <div class="text-xs text-neutral-500">On file</div>
            <div class="mt-1 text-xl font-semibold tabular-nums text-neutral-100">{{ $this->total }}</div>
        </div>
        <div>
            <div class="text-xs text-neutral-500">Expiring ≤ 90d</div>
            <div class="mt-1 text-xl font-semibold tabular-nums {{ $this->expiring90 > 0 ? 'text-amber-400' : 'text-neutral-400' }}">{{ $this->expiring90 }}</div>
        </div>
    </div>

    @if ($this->soonest->isNotEmpty())
        <ul class="space-y-1.5 border-t border-neutral-800 pt-3 text-sm">
            @foreach ($this->soonest as $doc)
                <li class="flex items-baseline justify-between gap-3">
                    <span class="truncate text-neutral-200">{{ $doc->label ?? ucfirst(str_replace('_', ' ', $doc->kind)) }}</span>
                    <span class="shrink-0 text-xs tabular-nums text-neutral-500">{{ $doc->expires_on->diffForHumans(['parts' => 1, 'short' => true]) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
