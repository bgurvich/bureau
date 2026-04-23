<x-mail::message>
# {{ $reminder->title }}

@if ($reminder->body)
{{ $reminder->body }}
@endif

**{{ __('When') }}:** {{ $reminder->remind_at?->format('F j, Y · H:i') }}

@if ($reminder->remindable_type && $reminder->remindable_id)
<small>{{ __('Related: :ref', ['ref' => class_basename($reminder->remindable_type).' #'.$reminder->remindable_id]) }}</small>
@endif

<x-mail::button :url="$url">
{{ __('Open Secretaire') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
