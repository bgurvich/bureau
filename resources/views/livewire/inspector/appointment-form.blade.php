<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div>
        <label for="i-ap-purpose" class="mb-1 block text-xs text-neutral-400">{{ __('Purpose') }}</label>
        <input wire:model="appointment_purpose" id="i-ap-purpose" type="text" autofocus
               placeholder="{{ __('Checkup, cleaning, follow-up…') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ap-starts" class="mb-1 block text-xs text-neutral-400">{{ __('Starts at') }}</label>
            <input wire:model="appointment_starts_at" id="i-ap-starts" type="datetime-local" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('appointment_starts_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-ap-ends" class="mb-1 block text-xs text-neutral-400">{{ __('Ends at') }}</label>
            <input wire:model="appointment_ends_at" id="i-ap-ends" type="datetime-local"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('appointment_ends_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-ap-provider" class="mb-1 block text-xs text-neutral-400">{{ __('Provider') }}</label>
            <select wire:model="appointment_provider_id" id="i-ap-provider"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="">—</option>
                @foreach ($this->healthProviders as $hp)
                    <option value="{{ $hp->id }}">{{ $hp->name }}@if ($hp->specialty) · {{ $hp->specialty }}@endif</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-ap-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
            <select wire:model="appointment_state" id="i-ap-state"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="scheduled">{{ __('Scheduled') }}</option>
                <option value="completed">{{ __('Completed') }}</option>
                <option value="cancelled">{{ __('Cancelled') }}</option>
                <option value="no_show">{{ __('No show') }}</option>
            </select>
        </div>
    </div>

    <div>
        <label for="i-ap-location" class="mb-1 block text-xs text-neutral-400">{{ __('Location') }}</label>
        <input wire:model="appointment_location" id="i-ap-location" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-300">
        <input wire:model="appointment_self_subject" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('This appointment is for me') }}
    </label>

    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
