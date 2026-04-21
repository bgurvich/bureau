<?php

use Laragear\WebAuthn\Models\WebAuthnCredential;
use Livewire\Component;

new class extends Component
{
    /** Reactive bump so the credential list re-queries after Alpine enrolls one. */
    public int $refreshKey = 0;

    /**
     * Fired by the passkey-manager Alpine component after a successful
     * `POST /webauthn/register`. Forces the list to re-render with the new
     * credential included.
     */
    public function refresh(): void
    {
        $this->refreshKey++;
    }

    public function delete(string $credentialId): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        WebAuthnCredential::whereKey($credentialId)->where('authenticatable_id', $user->id)->delete();
        $this->refreshKey++;
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'credentials' => $user
                ? WebAuthnCredential::where('authenticatable_id', $user->id)->orderByDesc('created_at')->get()
                : collect(),
        ];
    }
}; ?>

<div x-data="passkeyManager()" x-on:passkey-enrolled.window="$wire.refresh()"
     wire:key="passkeys-{{ $refreshKey }}" class="space-y-4">
    <div class="flex items-center gap-3">
        <input x-model="alias" type="text"
               placeholder="{{ __('e.g. My laptop, YubiKey 5C') }}"
               aria-label="{{ __('Name for this passkey') }}"
               class="flex-1 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
        <button type="button" x-on:click="enroll()" x-bind:disabled="busy"
                class="rounded-md border border-emerald-700/60 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100 hover:border-emerald-500 hover:bg-emerald-900/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
            <span x-show="!busy">{{ __('Register this device') }}</span>
            <span x-show="busy">{{ __('Waiting on your device…') }}</span>
        </button>
    </div>
    <p x-show="error" x-text="error" role="alert" class="text-xs text-rose-400"></p>
    <p x-show="notice" x-text="notice" role="status" class="text-xs text-emerald-300"></p>

    @if ($credentials->isEmpty())
        <p class="text-xs text-neutral-500">{{ __('No passkeys registered yet.') }}</p>
    @else
        <ul class="divide-y divide-neutral-800 rounded-md border border-neutral-800">
            @foreach ($credentials as $c)
                <li class="flex items-center justify-between px-3 py-2 text-xs">
                    <div>
                        <div class="font-medium text-neutral-100">{{ $c->alias ?: __('Unnamed passkey') }}</div>
                        <div class="text-[11px] text-neutral-500">
                            {{ __('Added :when', ['when' => $c->created_at?->diffForHumans()]) }}
                        </div>
                    </div>
                    <button type="button"
                            wire:click="delete('{{ $c->id }}')"
                            wire:confirm="{{ __('Remove this passkey? You won\'t be able to sign in with it again.') }}"
                            class="rounded border border-rose-800/40 bg-rose-900/20 px-2 py-1 text-[11px] text-rose-200 hover:border-rose-600 hover:bg-rose-900/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Remove') }}
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
