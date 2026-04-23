@php
    use App\Support\Formatting;
    use App\Support\MagicLink;
    $cur = $payload['currency'];
    $fmt = fn ($n) => Formatting::money($n, $cur);
    // One-click auto-login URL — recipient lands on the dashboard without
    // typing a password. Signature expires in 48h; bound to this user only.
    $magicUrl = MagicLink::to($user, 'dashboard');
@endphp
<p>{{ __('Hi :name,', ['name' => $user->name ?: __('there')]) }}</p>

<p>{{ __('Week of :start – :end.', ['start' => $payload['window_start'], 'end' => $payload['window_end']]) }}</p>

<h3>{{ __('What changed') }}</h3>
<ul>
    <li>
        <strong>{{ $payload['new_transactions_count'] }}</strong>
        {{ __('new transactions') }}
        ({{ __('net') }} {{ $fmt($payload['new_transactions_net']) }})
    </li>
    <li><strong>{{ $payload['completed_tasks_count'] }}</strong> {{ __('tasks completed') }}</li>
</ul>

<h3>{{ __('What\'s coming') }}</h3>
<ul>
    <li>{{ __(':n tasks due next week', ['n' => $payload['upcoming_tasks_count']]) }}</li>
    <li>
        {{ __(':n bills due next week', ['n' => $payload['upcoming_bills_count']]) }}
        @if ($payload['upcoming_bills_total'] > 0)
            · {{ $fmt($payload['upcoming_bills_total']) }}
        @endif
    </li>
    @if (($payload['active_subscriptions_count'] ?? 0) > 0)
        <li>
            {{ __(':n active subscriptions', ['n' => $payload['active_subscriptions_count']]) }}
            @if (($payload['active_subscriptions_monthly'] ?? 0) > 0)
                · {{ $fmt($payload['active_subscriptions_monthly']) }}/mo
            @endif
        </li>
    @endif
</ul>

@if (! empty($payload['expiring_contracts']))
    <h3>{{ __('Auto-renewing contracts ending ≤ 14 days') }}</h3>
    <ul>
        @foreach ($payload['expiring_contracts'] as $c)
            <li>
                <strong>{{ $c['title'] }}</strong> — {{ $c['ends_on'] }}
                @if (! empty($c['cancellation_url']))
                    · <a href="{{ $c['cancellation_url'] }}">{{ __('cancel') }}</a>
                @elseif (! empty($c['cancellation_email']))
                    · <a href="mailto:{{ $c['cancellation_email'] }}">{{ $c['cancellation_email'] }}</a>
                @endif
            </li>
        @endforeach
    </ul>
@endif

<p style="margin-top:24px">
    <a href="{{ $magicUrl }}" style="display:inline-block;padding:10px 18px;background:#0a0a0a;color:#fff;border-radius:6px;text-decoration:none;font-weight:500">
        {{ __('Open Secretaire') }}
    </a>
</p>

<p style="color:#888;font-size:12px;margin-top:32px">
    {{ __('You\'re getting this because you subscribe to Secretaire\'s weekly digest. Reply with a tip if anything looked off.') }}
</p>
<p style="color:#888;font-size:11px;margin-top:8px">
    {{ __('The "Open Secretaire" button signs you in automatically and expires in 48 hours.') }}
</p>
