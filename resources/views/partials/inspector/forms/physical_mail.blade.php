<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-pm-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="pm_kind" id="i-pm-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::physicalMailKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
            @error('pm_kind')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-pm-received" class="mb-1 block text-xs text-neutral-400">{{ __('Received on') }}</label>
            <input wire:model="pm_received_on" id="i-pm-received" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('pm_received_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-pm-subject" class="mb-1 block text-xs text-neutral-400">{{ __('Subject') }}</label>
        <input wire:model="title" id="i-pm-subject" type="text" autofocus
               placeholder="{{ __('Short label to recognize this piece later') }}"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div>
        <label for="i-pm-sender" class="mb-1 block text-xs text-neutral-400">{{ __('Sender') }}</label>
        <select wire:model="pm_sender_id" id="i-pm-sender"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="">—</option>
            @foreach (\App\Models\Contact::orderBy('display_name')->get(['id', 'display_name']) as $c)
                <option value="{{ $c->id }}">{{ $c->display_name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="i-pm-summary" class="mb-1 block text-xs text-neutral-400">{{ __('Summary') }}</label>
        <textarea wire:model="description" id="i-pm-summary" rows="3"
                  placeholder="{{ __('What does it say? Any key dates or amounts.') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <label class="flex items-center gap-2 text-sm text-neutral-200">
            <input wire:model="pm_action_required" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            <span>{{ __('Action required') }}</span>
        </label>
        <div>
            <label for="i-pm-processed" class="mb-1 block text-xs text-neutral-400">{{ __('Processed at') }}</label>
            <input wire:model="pm_processed_at" id="i-pm-processed" type="datetime-local"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <p class="mt-1 text-[11px] text-neutral-500">{{ __('Set when you\'re done with this piece.') }}</p>
        </div>
    </div>

    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
