{{-- Standard index-page table scaffold. Wraps the outer rounded-xl border +
     muted bg + overflow-hidden so rows clip cleanly. Expects a <thead>
     and <tbody> inside — no assumptions about columns or sort state.
     Pair with <x-ui.sortable-header> for column sorting. --}}
<div {{ $attributes->class('overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900/40') }}>
    <table class="w-full text-sm">
        {{ $slot }}
    </table>
</div>
