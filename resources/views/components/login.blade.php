<?php

use App\Models\User;
use App\Support\LoginRecorder;
use Illuminate\Support\Collection;
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

    #[Validate('nullable|string')]
    public string $password = '';

    public bool $remember = false;

    public function submit()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            LoginRecorder::failure(LoginRecorder::METHOD_PASSWORD, 'invalid-credentials', $this->email);
            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match our records.'),
            ]);
        }

        LoginRecorder::success(LoginRecorder::METHOD_PASSWORD, Auth::user());
        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Dev-only shortcut — click an account pill to sign in without a
     * password. Guarded by env so it never ships to production.
     */
    public function devLogin(int $userId)
    {
        abort_unless(app()->isLocal(), 403);

        $user = User::findOrFail($userId);
        Auth::login($user);
        LoginRecorder::success(LoginRecorder::METHOD_PASSWORD, $user);
        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * @return Collection<int, User>
     */
    public function devAccounts(): Collection
    {
        if (! app()->isLocal()) {
            return collect();
        }

        return User::orderBy('email')->limit(10)->get(['id', 'name', 'email']);
    }

    /**
     * Which social providers have credentials wired in config. Empty-config
     * providers stay hidden so the login page doesn't show dead buttons.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function socialProviders(): array
    {
        $all = [
            ['id' => 'google', 'label' => __('Google')],
            ['id' => 'github', 'label' => __('GitHub')],
            ['id' => 'microsoft', 'label' => __('Microsoft')],
            ['id' => 'apple', 'label' => __('Apple')],
        ];

        return array_values(array_filter($all, fn ($p) => ((string) config('services.'.$p['id'].'.client_id', '')) !== ''));
    }
};
?>

<div class="space-y-5">
    @php($devAccounts = $this->devAccounts())
    @if ($devAccounts->isNotEmpty())
        <div class="rounded-md border border-amber-700/60 bg-amber-900/20 p-3"
             role="region" aria-label="{{ __('Developer sign-in shortcut') }}">
            <p class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-amber-300">
                {{ __('Dev accounts · click to sign in') }}
            </p>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($devAccounts as $acc)
                    <button type="button"
                            wire:click="devLogin({{ $acc->id }})"
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-600/60 bg-amber-900/40 px-2.5 py-1 text-xs text-amber-100 transition hover:border-amber-400 hover:bg-amber-800/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300">
                        <span class="font-medium">{{ $acc->name ?: $acc->email }}</span>
                        @if ($acc->name)
                            <span class="text-amber-400/70">{{ $acc->email }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if (session('magic_link_sent'))
        <div role="status"
             class="rounded-md border border-emerald-800/40 bg-emerald-900/20 px-4 py-3 text-sm text-emerald-200">
            {{ session('magic_link_sent') }}
        </div>
    @endif

    <div x-data="passkeyLogin()" class="space-y-2">
        <button type="button" x-on:click="signIn()" x-bind:disabled="busy"
                class="flex w-full items-center justify-center gap-2 rounded-md border border-emerald-700/60 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100 transition hover:border-emerald-500 hover:bg-emerald-900/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300 disabled:opacity-60">
            <span x-show="!busy">{{ __('Sign in with a passkey') }}</span>
            <span x-show="busy">{{ __('Waiting on your device…') }}</span>
        </button>
        <p x-show="error" x-text="error" role="alert" class="text-xs text-rose-400"></p>
    </div>
    <div class="relative flex items-center gap-3 text-[10px] uppercase tracking-wider text-neutral-500">
        <span class="h-px flex-1 bg-neutral-800"></span>
        <span>{{ __('or') }}</span>
        <span class="h-px flex-1 bg-neutral-800"></span>
    </div>

    @php($providers = $this->socialProviders())
    @if ($providers !== [])
        <div class="space-y-2">
            @foreach ($providers as $p)
                <a href="{{ route('social.redirect', ['provider' => $p['id']]) }}"
                   class="flex w-full items-center justify-center gap-2 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 transition hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    {{ __('Continue with :provider', ['provider' => $p['label']]) }}
                </a>
            @endforeach
        </div>
        <div class="relative flex items-center gap-3 text-[10px] uppercase tracking-wider text-neutral-500">
            <span class="h-px flex-1 bg-neutral-800"></span>
            <span>{{ __('or') }}</span>
            <span class="h-px flex-1 bg-neutral-800"></span>
        </div>
    @endif

    <form wire:submit="submit" class="space-y-4 rounded-xl border border-neutral-800 bg-neutral-900/60 p-6 shadow-xl"
          aria-labelledby="login-heading" novalidate>
        <h2 id="login-heading" class="sr-only">{{ __('Sign in with email') }}</h2>
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
            <input wire:model="password" id="password" type="password" autocomplete="current-password"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                   class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            @error('password')<div id="password-error" role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
        </div>

        <label class="flex items-center gap-2 text-xs text-neutral-400">
            <input wire:model="remember" type="checkbox" class="rounded border-neutral-700 bg-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <span>{{ __('Remember me on this device') }}</span>
        </label>

        <button type="submit"
                class="w-full rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <span wire:loading.remove wire:target="submit">{{ __('Sign in with password') }}</span>
            <span wire:loading wire:target="submit">{{ __('Signing in…') }}</span>
        </button>
    </form>

    <form method="POST" action="{{ route('magic-link.request') }}"
          class="space-y-3 rounded-xl border border-neutral-800 bg-neutral-900/40 p-6 text-sm"
          aria-labelledby="magic-heading">
        @csrf
        <h2 id="magic-heading" class="text-xs font-medium uppercase tracking-wider text-neutral-500">
            {{ __('Or email me a sign-in link') }}
        </h2>
        <div class="flex flex-col gap-2 sm:flex-row">
            <input name="email" type="email" required placeholder="you@example.com"
                   autocomplete="email" inputmode="email" spellcheck="false"
                   aria-label="{{ __('Email for sign-in link') }}"
                   class="flex-1 rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            <button type="submit"
                    class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                {{ __('Email me a link') }}
            </button>
        </div>
        <p class="text-[11px] text-neutral-500">
            {{ __('We\'ll send a sign-in link good for 15 minutes. No password needed.') }}
        </p>
    </form>
</div>
