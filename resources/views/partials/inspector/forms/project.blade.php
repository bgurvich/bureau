<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
            <label for="i-p-name" class="mb-1 block text-xs text-neutral-400">{{ __('Name') }}</label>
            <input wire:model="project_name" id="i-p-name" type="text" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('project_name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-p-color" class="mb-1 block text-xs text-neutral-400">{{ __('Color') }}</label>
            <input wire:model="project_color" id="i-p-color" type="color"
                   class="h-[38px] w-full rounded-md border border-neutral-700 bg-neutral-950 px-1 py-1 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div>
        <label for="i-p-client" class="mb-1 block text-xs text-neutral-400">{{ __('Client') }}</label>
        <x-ui.searchable-select
            id="i-p-client"
            model="project_client_id"
            :options="['' => '—'] + $this->contacts->mapWithKeys(fn ($c) => [$c->id => $c->display_name])->all()"
            placeholder="—"
            allow-create
            create-method="createCounterparty" />
    </div>
    <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model.live="project_billable" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Billable') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="project_archived" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Archived') }}
        </label>
    </div>
    @if ($project_billable)
        <div>
            <label for="i-p-rate" class="mb-1 block text-xs text-neutral-400">{{ __('Hourly rate') }}
                <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $project_hourly_rate_currency }}</span>
            </label>
            <input wire:model="project_hourly_rate" id="i-p-rate" type="number" step="0.01"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    @endif
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
