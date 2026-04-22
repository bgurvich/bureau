<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-te-date" class="mb-1 block text-xs text-neutral-400">{{ __('Date') }}</label>
            <input wire:model="activity_date" id="i-te-date" type="date" required autofocus
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('activity_date')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-te-hours" class="mb-1 block text-xs text-neutral-400">{{ __('Hours') }}</label>
            <input wire:model="hours" id="i-te-hours" type="number" step="0.25" min="0.01" max="24" required
                   placeholder="2.5"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('hours')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
    </div>

    <div>
        <label for="i-te-project" class="mb-1 block text-xs text-neutral-400">{{ __('Project') }}</label>
        <x-ui.searchable-select
            id="i-te-project"
            model="project_id"
            :options="['' => '—'] + \App\Models\Project::orderBy('name')->pluck('name', 'id')->all()"
            placeholder="—" />
        @error('project_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-te-task" class="mb-1 block text-xs text-neutral-400">{{ __('Task') }}</label>
        <x-ui.searchable-select
            id="i-te-task"
            model="task_id"
            :options="['' => '—'] + \App\Models\Task::orderByDesc('created_at')->limit(100)->pluck('title', 'id')->all()"
            placeholder="—" />
        @error('task_id')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <div>
        <label for="i-te-desc" class="mb-1 block text-xs text-neutral-400">{{ __('Description') }}</label>
        <textarea wire:model="description" id="i-te-desc" rows="2"
                  placeholder="{{ __('What did you work on?') }}"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
        @error('description')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>

    <label class="flex items-center gap-2 text-sm text-neutral-300">
        <input wire:model="billable" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
        {{ __('Billable') }}
    </label>
</form>
