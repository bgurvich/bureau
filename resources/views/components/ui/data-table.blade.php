{{-- Standard index-page table scaffold. Wraps the outer rounded-xl border +
     muted bg + overflow-x-auto so wide tables scroll horizontally on narrow
     viewports instead of overflowing the page. Expects a <thead> and
     <tbody> inside — no assumptions about columns or sort state.
     Pair with <x-ui.sortable-header> for column sorting. --}}
<div {{ $attributes->class('overflow-x-auto rounded-xl border border-neutral-800 bg-neutral-900/40') }}>
    <table class="w-full min-w-[40rem] text-sm">
        {{ $slot }}
    </table>
</div>
