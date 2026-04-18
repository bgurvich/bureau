<form wire:submit="save" class="space-y-4" novalidate>
    {{-- hidden submit button enables implicit Enter-to-submit --}}
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-task-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="title" id="i-task-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="i-task-desc" class="mb-1 block text-xs text-neutral-400">{{ __('Description') }}</label>
        <textarea wire:model="description" id="i-task-desc" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-task-due" class="mb-1 block text-xs text-neutral-400">{{ __('Due') }}</label>
            <input wire:model="due_at" id="i-task-due" type="datetime-local"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        </div>
        <div>
            <label for="i-task-prio" class="mb-1 block text-xs text-neutral-400">{{ __('Priority') }}</label>
            <select wire:model="priority" id="i-task-prio"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="1">1 — {{ __('High') }}</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5 — {{ __('Low') }}</option>
            </select>
        </div>
    </div>
    <div>
        <label for="i-task-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
        <select wire:model="state" id="i-task-state"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @foreach (App\Support\Enums::taskStates() as $v => $l)
                <option value="{{ $v }}">{{ $l }}</option>
            @endforeach
        </select>
    </div>
    @include('partials.inspector.fields.tags')
    @include('partials.inspector.fields.admin')
</form>
