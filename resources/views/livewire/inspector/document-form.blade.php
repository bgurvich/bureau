<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-doc-kind" class="mb-1 block text-xs text-neutral-400">{{ __('Kind') }}</label>
            <select wire:model="doc_kind" id="i-doc-kind" required
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                @foreach (App\Support\Enums::documentKinds() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="i-doc-label" class="mb-1 block text-xs text-neutral-400">{{ __('Label') }}</label>
            <input wire:model="doc_label" id="i-doc-label" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-doc-number" class="mb-1 block text-xs text-neutral-400">{{ __('Number') }}</label>
            <input wire:model="doc_number" id="i-doc-number" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-doc-issuer" class="mb-1 block text-xs text-neutral-400">{{ __('Issuer') }}</label>
            <input wire:model="doc_issuer" id="i-doc-issuer" type="text"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-doc-issued" class="mb-1 block text-xs text-neutral-400">{{ __('Issued on') }}</label>
            <input wire:model="doc_issued_on" id="i-doc-issued" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-doc-expires" class="mb-1 block text-xs text-neutral-400">{{ __('Expires on') }}</label>
            <input wire:model="doc_expires_on" id="i-doc-expires" type="date"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm text-neutral-200">
        <input wire:model="in_case_of_pack" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Include in the in-case-of-emergency pack') }}
    </label>
    @include('partials.inspector.fields.photos')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
