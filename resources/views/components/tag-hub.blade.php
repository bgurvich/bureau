<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Media;
use App\Models\Note;
use App\Models\Property;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app', ['title' => 'Tag'])]
class extends Component
{
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    #[Computed]
    public function tag(): ?Tag
    {
        return Tag::where('slug', $this->slug)->first();
    }

    /**
     * @return array<int, array{group: string, type: ?string, title: string, subtitle: string, id: ?int}>
     */
    #[Computed]
    public function hits(): array
    {
        if (! $this->tag) {
            return [];
        }

        $rows = [];

        $types = [
            ['model' => Note::class, 'group' => __('Notes'), 'type' => 'note',
                'title' => fn ($m) => $m->title ?: Illuminate\Support\Str::limit((string) $m->body, 60)],
            ['model' => Task::class, 'group' => __('Tasks'), 'type' => 'task',
                'title' => fn ($m) => $m->title],
            ['model' => Contact::class, 'group' => __('Contacts'), 'type' => 'contact',
                'title' => fn ($m) => $m->display_name],
            ['model' => Transaction::class, 'group' => __('Transactions'), 'type' => 'transaction',
                'title' => fn ($m) => $m->description ?? '—'],
            ['model' => Contract::class, 'group' => __('Contracts'), 'type' => 'contract',
                'title' => fn ($m) => $m->title],
            ['model' => Document::class, 'group' => __('Documents'), 'type' => 'document',
                'title' => fn ($m) => $m->label ?: $m->kind],
            ['model' => Account::class, 'group' => __('Accounts'), 'type' => 'account',
                'title' => fn ($m) => $m->name],
            ['model' => Property::class, 'group' => __('Properties'), 'type' => 'property',
                'title' => fn ($m) => $m->name],
            ['model' => Vehicle::class, 'group' => __('Vehicles'), 'type' => 'vehicle',
                'title' => fn ($m) => trim(($m->year ? $m->year.' ' : '').($m->make ?? '').' '.($m->model ?? ''))],
            ['model' => InventoryItem::class, 'group' => __('Inventory'), 'type' => 'inventory',
                'title' => fn ($m) => $m->name],
            ['model' => Media::class, 'group' => __('Media'), 'type' => null,
                'title' => fn ($m) => $m->original_name ?? basename((string) $m->path)],
        ];

        foreach ($types as $t) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
            $class = $t['model'];
            $items = $class::whereHas('tags', fn ($q) => $q->where('tags.id', $this->tag->id))
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            foreach ($items as $m) {
                $rows[] = [
                    'group' => $t['group'],
                    'type' => $t['type'],
                    'id' => (int) $m->id,
                    'title' => $t['title']($m) ?: '—',
                    'subtitle' => '',
                ];
            }
        }

        return $rows;
    }
};
?>

<div class="space-y-5">
    @if (! $this->tag)
        <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
            {{ __('Tag not found.') }}
            <div class="mt-3">
                <a href="{{ route('tags.index') }}" class="text-neutral-300 hover:text-neutral-100">← {{ __('All tags') }}</a>
            </div>
        </div>
    @else
        <header class="flex items-baseline justify-between gap-4">
            <div>
                <h2 class="flex items-center gap-2 text-base font-semibold text-neutral-100">
                    <span class="text-neutral-500">#</span>{{ $this->tag->slug }}
                </h2>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ trans_choice('{0} Nothing tagged yet|{1} :count item|[2,*] :count items', count($this->hits), ['count' => count($this->hits)]) }}
                    @if ($this->tag->name !== $this->tag->slug) · {{ $this->tag->name }} @endif
                </p>
            </div>
            <a href="{{ route('tags.index') }}" class="text-xs text-neutral-500 hover:text-neutral-300">← {{ __('All tags') }}</a>
        </header>

        @php $byGroup = collect($this->hits)->groupBy('group'); @endphp

        @if ($byGroup->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-800 bg-neutral-900/40 p-10 text-center text-sm text-neutral-500">
                {{ __('Nothing tagged with this tag yet.') }}
            </div>
        @else
            <div class="space-y-6">
                @foreach ($byGroup as $group => $rows)
                    <section aria-label="{{ $group }}" class="space-y-2">
                        <h3 class="text-[10px] font-medium uppercase tracking-wider text-neutral-500">
                            {{ $group }}
                            <span class="ml-1 text-neutral-600">· {{ count($rows) }}</span>
                        </h3>
                        <ul class="divide-y divide-neutral-800 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40">
                            @foreach ($rows as $r)
                                <li class="px-4 py-2 text-sm">
                                    @if ($r['type'])
                                        <button type="button"
                                                wire:click="$dispatch('inspector-open', {{ json_encode(['type' => $r['type'], 'id' => $r['id']]) }})"
                                                class="w-full truncate text-left text-neutral-100 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ $r['title'] }}
                                        </button>
                                    @else
                                        <a href="{{ route('records.media', ['focus' => $r['id']]) }}"
                                           class="block truncate text-neutral-100 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                            {{ $r['title'] }}
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    @endif
</div>
