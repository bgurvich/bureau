<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-ml-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="kind" id="i-ml-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::mediaLogKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2">
            <label for="i-ml-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
            <input wire:model="title" id="i-ml-title" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-ml-creator" class="mb-1 block text-xs text-neutral-400">{{ __('Author / creator') }}</label>
        <input wire:model="creator" id="i-ml-creator" type="text"
               placeholder="{{ __('Author, director, host, studio…') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-ml-status" class="mb-1 block text-xs text-neutral-400">{{ __('Status') }}</label>
            <select wire:model.live="status" id="i-ml-status" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::mediaLogStatuses() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ml-started" class="mb-1 block text-xs text-neutral-400">{{ __('Started on') }}</label>
            <input wire:model="started_on" id="i-ml-started" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-ml-finished" class="mb-1 block text-xs text-neutral-400">{{ __('Finished on') }}</label>
            <input wire:model="finished_on" id="i-ml-finished" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('finished_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-ml-rating" class="mb-1 block text-xs text-neutral-400">{{ __('Rating (1–5)') }}</label>
            <select wire:model="rating" id="i-ml-rating"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ([1, 2, 3, 4, 5] as $r)
                    <option value="{{ $r }}">{{ str_repeat('★', $r) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2">
            <label for="i-ml-url" class="mb-1 block text-xs text-neutral-400">{{ __('Link') }}</label>
            <input wire:model="external_url" id="i-ml-url" type="url"
                   placeholder="https://…"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('external_url')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
