@component('mail::message')
# {{ __('You\'re invited to join :household', ['household' => $householdName]) }}

@if ($inviterName)
{{ __(':inviter has invited you to join their household on :app as a :role.', ['inviter' => $inviterName, 'app' => config('app.name'), 'role' => $role]) }}
@else
{{ __('You\'ve been invited to join :household on :app as a :role.', ['household' => $householdName, 'app' => config('app.name'), 'role' => $role]) }}
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept invitation') }}
@endcomponent

{{ __('This link expires on :when and only works once.', ['when' => $expiresAt->format('Y-m-d H:i T')]) }}

{{ __("If you weren't expecting this, you can safely ignore this email.") }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent
