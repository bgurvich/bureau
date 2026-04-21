@component('mail::message')
# {{ __('Sign in to :app', ['app' => config('app.name')]) }}

{{ __('Hi :name,', ['name' => $name]) }}

{{ __("Click the button below to sign in. The link expires in 15 minutes and only works once.") }}

@component('mail::button', ['url' => $url])
{{ __('Sign in') }}
@endcomponent

{{ __("If you didn't request this, you can safely ignore this email.") }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent
