<x-layouts.portal :title="__('Portal link expired')">
    <div class="mx-auto max-w-sm rounded-xl border border-neutral-800 bg-neutral-900/40 p-8 text-center">
        <h1 class="text-base font-semibold text-neutral-100">{{ __('Link is no longer active') }}</h1>
        <p class="mt-2 text-sm text-neutral-400">
            {{ __('This portal link has expired or been revoked. Ask the household owner for a fresh invite.') }}
        </p>
    </div>
</x-layouts.portal>
