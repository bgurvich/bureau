@props([
    'as' => 'div',
])

{{-- Standard "nothing here yet" card. Accepts any element tag via `as`
     (defaults to `div` to match the plurality). Content slot carries the
     message. Keep copy short — a sentence and optionally a hint. --}}
<{{ $as }} {{ $attributes->class('rounded-xl border border-neutral-800 bg-neutral-900/40 p-6 text-sm text-neutral-500') }}>
    {{ $slot }}
</{{ $as }}>
