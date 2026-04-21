<?php

use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Support\LoginRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.auth')]
class extends Component
{
    public string $token = '';

    public ?string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $error = null;

    /**
     * Resolved + cached state for the template. Loaded in mount so both
     * render paths (new user form, existing-account confirmation) can
     * address the same invitation without re-hitting the DB.
     *
     * @var array{
     *     invite: HouseholdInvitation,
     *     existingUser: ?User,
     *     householdName: string,
     * }|null
     */
    public ?array $state = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $invite = HouseholdInvitation::findByToken($token);

        if (! $invite) {
            $this->error = __('This invitation link is invalid. It may have been revoked.');

            return;
        }
        if ($invite->isAccepted()) {
            $this->error = __('This invitation has already been used. Sign in with your account instead.');

            return;
        }
        if ($invite->isExpired()) {
            $this->error = __('This invitation has expired. Ask the sender to issue a new one.');

            return;
        }

        $invite->loadMissing('household', 'invitedBy');
        $existing = User::where('email', $invite->email)->first();
        $this->name = $existing?->name;

        $this->state = [
            'invite' => $invite,
            'existingUser' => $existing,
            'householdName' => $invite->household?->name ?? '',
        ];
    }

    /**
     * Accept the invite. Two paths:
     *  - No account exists for this email → create the User from $name +
     *    $password, log them in, attach to the household.
     *  - Account already exists → require password match (they signed up
     *    before being invited); on match, log them in and attach.
     * Either way we flip the invitation's accepted_at + accepted_user_id
     * so the same token can't be reused.
     */
    public function accept()
    {
        $invite = HouseholdInvitation::findByToken($this->token);
        if (! $invite || $invite->isAccepted() || $invite->isExpired()) {
            $this->error = __('This invitation is no longer usable. Ask the sender to issue a new one.');

            return null;
        }

        $existing = User::where('email', $invite->email)->first();

        if ($existing) {
            $this->validate(['password' => 'required|string']);
            if (! Hash::check($this->password, (string) $existing->password)) {
                $this->addError('password', __('That password doesn\'t match the existing account.'));

                return null;
            }
            $user = $existing;
        } else {
            $this->validate([
                'name' => 'required|string|max:120',
                'password' => 'required|string|min:8|confirmed',
            ]);
            $user = User::create([
                'name' => $this->name,
                'email' => $invite->email,
                'password' => $this->password,
                'default_household_id' => $invite->household_id,
            ]);
        }

        $invite->household->users()->syncWithoutDetaching([
            $user->id => ['role' => $invite->role, 'joined_at' => now()],
        ]);

        if (! $user->default_household_id) {
            $user->forceFill(['default_household_id' => $invite->household_id])->save();
        }

        $invite->forceFill([
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();

        Auth::login($user);
        session()->regenerate();
        LoginRecorder::success(LoginRecorder::METHOD_PASSWORD, $user);

        return redirect()->route('dashboard');
    }
};
?>

<div class="space-y-5">
    @if ($error)
        <div role="alert"
             class="rounded-md border border-rose-800/50 bg-rose-950/30 px-4 py-3 text-sm text-rose-200">
            {{ $error }}
        </div>
        <a href="{{ route('login') }}"
           class="inline-block rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 hover:border-neutral-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
            {{ __('Back to sign in') }}
        </a>
    @elseif ($state)
        <div class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
            <h2 class="text-base font-semibold text-neutral-100">
                {{ __('Join :household', ['household' => $state['householdName']]) }}
            </h2>
            <p class="mt-1 text-xs text-neutral-500">
                @if ($state['existingUser'])
                    {{ __('An account exists for :email. Enter your password to join the household.', ['email' => $state['invite']->email]) }}
                @else
                    {{ __('Create an account for :email to accept this invitation.', ['email' => $state['invite']->email]) }}
                @endif
            </p>

            <form wire:submit="accept" class="mt-4 space-y-4" novalidate>
                @if (! $state['existingUser'])
                    <div>
                        <label for="inv-name" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Your name') }}</label>
                        <input wire:model="name" id="inv-name" type="text" required autofocus
                               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        @error('name')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                    </div>
                @endif

                <div>
                    <label for="inv-password" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Password') }}</label>
                    <input wire:model="password" id="inv-password" type="password"
                           autocomplete="{{ $state['existingUser'] ? 'current-password' : 'new-password' }}" required
                           class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    @error('password')<div role="alert" class="mt-1 text-xs text-rose-400">{{ $message }}</div>@enderror
                </div>

                @if (! $state['existingUser'])
                    <div>
                        <label for="inv-password-confirm" class="mb-1 block text-xs font-medium text-neutral-400">{{ __('Confirm password') }}</label>
                        <input wire:model="password_confirmation" id="inv-password-confirm" type="password" autocomplete="new-password" required
                               class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    </div>
                @endif

                <button type="submit"
                        class="w-full rounded-md bg-neutral-100 px-3 py-2 text-sm font-medium text-neutral-900 transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                    <span wire:loading.remove wire:target="accept">{{ __('Accept and join') }}</span>
                    <span wire:loading wire:target="accept">{{ __('Joining…') }}</span>
                </button>
            </form>
        </div>
    @endif
</div>
