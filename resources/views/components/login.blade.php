<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Layout('components.layouts.auth')]
class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string|min:1')]
    public string $password = '';

    public bool $remember = false;

    public function submit()
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match our records.'),
            ]);
        }

        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
};
?>

<div>
    <form wire:submit="submit" class="space-y-4 rounded-xl border border-neutral-800 bg-neutral-900/60 p-6 shadow-xl"
          aria-labelledby="login-heading" novalidate>
        <h2 id="login-heading" class="sr-only">{{ __('Sign in') }}</h2>
        <div>
            <label for="email" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Email') }}</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" autofocus required
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"
                   placeholder="you@example.com">
            @error('email')<div id="email-error" role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Password') }}</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('password')<div id="password-error" role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <label class="flex items-center gap-2 text-xs text-neutral-400">
            <input wire:model="remember" type="checkbox" class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <span>{{ __('Remember me on this device') }}</span>
        </label>

        <button type="submit"
                class="w-full rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 transition hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <span wire:loading.remove wire:target="submit">{{ __('Sign in') }}</span>
            <span wire:loading wire:target="submit">{{ __('Signing in…') }}</span>
        </button>
    </form>
</div>
