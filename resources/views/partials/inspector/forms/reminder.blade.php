<form wire:submit="save" class="space-y-4" novalidate>
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">{{ __('Submit') }}</button>
    <div>
        <label for="i-rem-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="reminder_title" id="i-rem-title" type="text" required autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        @error('reminder_title')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="i-rem-at" class="mb-1 block text-xs text-neutral-400">{{ __('Remind at') }}</label>
            <input wire:model="reminder_remind_at" id="i-rem-at" type="datetime-local" required
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('reminder_remind_at')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="i-rem-ch" class="mb-1 block text-xs text-neutral-400">{{ __('Channel') }}</label>
            <select wire:model="reminder_channel" id="i-rem-ch"
                    class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <option value="in_app">{{ __('in app') }}</option>
                <option value="email">{{ __('email') }}</option>
                <option value="slack">{{ __('slack') }}</option>
                <option value="sms">{{ __('sms') }}</option>
                <option value="telegram">{{ __('telegram') }}</option>
                <option value="push">{{ __('push') }}</option>
            </select>
        </div>
    </div>
    <div>
        <label for="i-rem-state" class="mb-1 block text-xs text-neutral-400">{{ __('State') }}</label>
        <select wire:model="reminder_state" id="i-rem-state"
                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <option value="pending">{{ __('pending') }}</option>
            <option value="fired">{{ __('fired') }}</option>
            <option value="acknowledged">{{ __('acknowledged') }}</option>
            <option value="cancelled">{{ __('cancelled') }}</option>
        </select>
    </div>
    <div>
        <label for="i-rem-notes" class="mb-1 block text-xs text-neutral-400">{{ __('Notes') }}</label>
        <textarea wire:model="reminder_notes" id="i-rem-notes" rows="3"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
    </div>
    @include('partials.inspector.fields.admin')
</form>
