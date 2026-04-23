<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label for="i-dc-date" class="mb-1 block text-xs text-neutral-400">{{ __('Decided on') }}</label>
            <input wire:model="decided_on" id="i-dc-date" type="date" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('decided_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div class="col-span-2">
            <label for="i-dc-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
            <input wire:model="title" id="i-dc-title" type="text" required autofocus
                   placeholder="{{ __('Switch to Namecheap for the domains, …') }}"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-dc-context" class="mb-1 block text-xs text-neutral-400">{{ __('Context') }}</label>
        <textarea wire:model="context" id="i-dc-context" rows="3"
                  placeholder="{{ __('What prompted this — constraints, timing, who was asking.') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm leading-relaxed text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    <div>
        <label for="i-dc-options" class="mb-1 block text-xs text-neutral-400">{{ __('Options considered') }}</label>
        <textarea wire:model="options_considered" id="i-dc-options" rows="3"
                  placeholder="{{ __('One per line — the alternatives you weighed.') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm leading-relaxed text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    <div>
        <label for="i-dc-chosen" class="mb-1 block text-xs text-neutral-400">{{ __('Chosen') }}</label>
        <input wire:model="chosen" id="i-dc-chosen" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
    </div>

    <div>
        <label for="i-dc-rationale" class="mb-1 block text-xs text-neutral-400">{{ __('Rationale') }}</label>
        <textarea wire:model="rationale" id="i-dc-rationale" rows="3"
                  placeholder="{{ __('Why this won. The line future-you reads first.') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm leading-relaxed text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    <div>
        <label for="i-dc-fup" class="mb-1 block text-xs text-neutral-400">{{ __('Follow up on') }}</label>
        <input wire:model="follow_up_on" id="i-dc-fup" type="date"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <p class="mt-1 text-[11px] text-neutral-500">{{ __('When this pings the Attention radar asking you to record the outcome.') }}</p>
        @error('follow_up_on')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-dc-outcome" class="mb-1 block text-xs text-neutral-400">{{ __('Outcome') }}</label>
        <textarea wire:model="outcome" id="i-dc-outcome" rows="3"
                  placeholder="{{ __('Filled in after the fact — what actually happened.') }}"
                  class="w-full resize-y rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm leading-relaxed text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>

    @include('partials.inspector.fields.subjects')
    @include('partials.inspector.fields.notes')
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
