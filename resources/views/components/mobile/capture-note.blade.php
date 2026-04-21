<?php

use App\Models\Note;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.mobile', ['title' => 'Note'])]
class extends Component
{
    public string $title = '';

    public string $body = '';

    public bool $pinned = false;

    public bool $private = false;

    public int $savedCount = 0;

    public function save(bool $andContinue = true): void
    {
        $this->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string',
        ]);

        Note::create([
            'user_id' => auth()->id(),
            'title' => trim($this->title) ?: null,
            'body' => $this->body,
            'pinned' => $this->pinned,
            'private' => $this->private,
        ]);

        $this->savedCount++;
        $this->reset(['title', 'body', 'pinned', 'private']);

        if (! $andContinue) {
            $this->redirectRoute('mobile.capture', navigate: false);
        }
    }
};
?>

<div class="space-y-5"
     x-data="{
        supported: 'webkitSpeechRecognition' in window || 'SpeechRecognition' in window,
        // want = user intent (what the button should show). listening = actual rec state.
        // onend fires on every pause in practice; we auto-restart while want is true so the
        // UI doesn't flip back to idle between phrases.
        want: false,
        rec: null,
        errorMessage: '',
        build(locale) {
            const Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
            const r = new Ctor();
            r.lang = locale || (navigator.language || 'en-US');
            r.continuous = true;
            r.interimResults = false;
            r.onresult = (e) => {
                let chunk = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    if (e.results[i].isFinal) { chunk += e.results[i][0].transcript; }
                }
                if (chunk) {
                    const current = this.$wire.body ?? '';
                    const separator = current && !current.match(/\s$/) ? ' ' : '';
                    this.$wire.set('body', current + separator + chunk.trim());
                }
            };
            r.onend = () => {
                if (this.want) { try { r.start(); } catch (e) { this.want = false; } }
            };
            r.onerror = (e) => {
                if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                    this.errorMessage = '{{ __('Microphone permission denied.') }}';
                    this.want = false;
                } else if (e.error === 'no-speech' || e.error === 'aborted') {
                    // benign — onend will restart if still wanted
                } else {
                    this.errorMessage = e.error;
                }
            };
            return r;
        },
        async start(locale) {
            if (!this.supported) { return; }
            this.errorMessage = '';

            // SpeechRecognition on Chrome/Edge fails silently with
            // 'not-allowed' if no microphone permission prompt has
            // fired for this origin. Explicitly request audio first
            // so the OS / browser surfaces the prompt; only then
            // instantiate the recognizer. Stop the MediaStream
            // immediately since SpeechRecognition opens its own.
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                stream.getTracks().forEach(t => t.stop());
            } catch (e) {
                this.errorMessage = '{{ __('Microphone permission denied.') }}';
                this.want = false;
                return;
            }

            this.want = true;
            try { this.rec = this.build(locale); this.rec.start(); }
            catch (e) { this.want = false; this.errorMessage = e.message || String(e); }
        },
        stop() {
            this.want = false;
            if (this.rec) { try { this.rec.stop(); } catch (e) {} }
        },
        async toggle(locale) { this.want ? this.stop() : await this.start(locale); }
     }">
    <header class="pt-2">
        <h1 class="text-lg font-semibold text-neutral-100">{{ __('New note') }}</h1>
        <p class="mt-1 text-xs text-neutral-500">{{ __('Dictate or type. Save when done.') }}</p>
    </header>

    @if ($savedCount > 0)
        <div class="rounded-lg border border-emerald-900/60 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-300" role="status" aria-live="polite">
            {{ trans_choice('{1} :count note saved|[2,*] :count notes saved', $savedCount, ['count' => $savedCount]) }}
        </div>
    @endif

    <div>
        <label for="n-title" class="mb-1 block text-xs text-neutral-400">{{ __('Title') }}</label>
        <input wire:model="title" id="n-title" type="text"
               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
               placeholder="{{ __('Optional') }}">
    </div>

    <div>
        <div class="mb-1 flex items-baseline justify-between">
            <label for="n-body" class="text-xs text-neutral-400">{{ __('Body') }}</label>
            <button type="button" x-on:click="toggle('{{ str_replace('_', '-', app()->getLocale()) }}')"
                    x-show="supported"
                    x-bind:class="want ? 'bg-rose-600/80 text-white' : 'bg-neutral-800 text-neutral-200'"
                    x-bind:aria-pressed="want"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="9" y="3" width="6" height="12" rx="3"/>
                    <path d="M5 12a7 7 0 0 0 14 0"/>
                    <path d="M12 19v3"/>
                </svg>
                <span x-text="want ? '{{ __('Listening — tap to stop') }}' : '{{ __('Dictate') }}'"></span>
            </button>
        </div>
        <textarea wire:model="body" id="n-body" rows="10"
                  class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                  placeholder="{{ __('Start typing — or tap Dictate.') }}"></textarea>
        @error('body')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        <p class="mt-1 text-[11px] text-neutral-600" x-show="!supported">
            {{ __('Voice input not supported in this browser. Type your note.') }}
        </p>
        <p class="mt-1 text-[11px] text-rose-400" x-show="errorMessage" x-text="errorMessage" role="alert"></p>
    </div>

    <div class="flex items-center gap-4 text-sm">
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="pinned" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Pin') }}
        </label>
        <label class="flex items-center gap-2 text-neutral-300">
            <input wire:model="private" type="checkbox" class="rounded border-neutral-700 bg-neutral-950">
            {{ __('Private') }}
        </label>
    </div>

    <div class="grid grid-cols-3 gap-2">
        <button type="button" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save"
                class="col-span-2 rounded-xl bg-emerald-600 px-3 py-3 text-sm font-medium text-white active:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-60">
            {{ __('Save & next') }}
        </button>
        <button type="button" wire:click="save(false)" wire:loading.attr="disabled" wire:target="save"
                class="rounded-xl border border-neutral-700 bg-neutral-900 px-3 py-3 text-sm text-neutral-200 active:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
            {{ __('Save & done') }}
        </button>
    </div>
</div>
