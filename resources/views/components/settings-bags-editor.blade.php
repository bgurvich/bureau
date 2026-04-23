<?php

use App\Support\CurrentHousehold;
use App\Support\Settings;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Schema-driven three-scope settings editor.
 *
 * Schema lives in config/settings.php (one block per scope). Each
 * setting declares a key, label, type (bool|int|string|text|enum|url|
 * email), optional env fallback, default, and description.
 *
 * Edit flow:
 *   - mount() reads `Settings::get('scope', 'key')` — walks DB → env
 *     → schema default — into a per-scope form state map.
 *   - saveScope() writes via Settings::replace(scope, data). Explicit-
 *     only; no auto-save.
 *   - coerce() drops values that equal the schema default so the DB
 *     bag stays compact and env fallback can keep flowing.
 */
new class extends Component
{
    /** @var array<string, array<string, mixed>> */
    public array $form = [
        'app' => [],
        'household' => [],
        'user' => [],
    ];

    /** @var array<string, ?string> */
    public array $statuses = [
        'app' => null,
        'household' => null,
        'user' => null,
    ];

    public function mount(): void
    {
        foreach (['app', 'household', 'user'] as $scope) {
            foreach (Settings::schema($scope) as $row) {
                $this->form[$scope][(string) $row['key']] = $row['effective'] ?? null;
            }
        }
    }

    public function saveApp(): void
    {
        Settings::replace('app', $this->coerce('app'));
        $this->statuses['app'] = __('Saved');
    }

    public function saveHousehold(): void
    {
        if (! CurrentHousehold::get()) {
            $this->statuses['household'] = __('No current household.');

            return;
        }
        Settings::replace('household', $this->coerce('household'));
        $this->statuses['household'] = __('Saved');
    }

    public function saveUser(): void
    {
        if (! auth()->user()) {
            $this->statuses['user'] = __('Not signed in.');

            return;
        }
        Settings::replace('user', $this->coerce('user'));
        $this->statuses['user'] = __('Saved');
    }

    /**
     * Type-coerce form state to the schema type + drop values equal to
     * the schema default. Clearing a field (empty string on non-bool)
     * lets the env fallback take back over.
     *
     * @return array<string, mixed>
     */
    private function coerce(string $scope): array
    {
        $out = [];
        foreach (Settings::schema($scope) as $row) {
            $key = (string) $row['key'];
            $type = (string) ($row['type'] ?? 'string');
            $raw = $this->form[$scope][$key] ?? null;
            $value = match ($type) {
                'bool' => (bool) $raw,
                'int' => is_numeric($raw) ? (int) $raw : null,
                default => is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : null),
            };

            if ($value === null || ($type !== 'bool' && $value === '')) {
                continue;
            }
            if (array_key_exists('default', $row) && $value === $row['default']) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function appSchema(): array
    {
        return Settings::schema('app');
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function householdSchema(): array
    {
        return Settings::schema('household');
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function userSchema(): array
    {
        return Settings::schema('user');
    }

    #[Computed]
    public function householdName(): string
    {
        return CurrentHousehold::get()?->name ?? __('Household');
    }
};
?>

@php
    /* Per-field source indicator — helps the user see whether the
       effective value came from the DB bag, the .env fallback, or the
       hard-coded default. */
    $sourceLabel = function (string $scope, array $row): string {
        $bag = \App\Support\Settings::bag($scope);
        if (array_key_exists($row['key'], $bag)) {
            return __('saved');
        }
        if (! empty($row['env']) && env($row['env']) !== null) {
            return __('from :env', ['env' => $row['env']]);
        }

        return __('default');
    };
@endphp

<section aria-labelledby="settings-bags-heading" class="rounded-xl border border-neutral-800 bg-neutral-900/40 p-5">
    <header class="mb-4">
        <h2 id="settings-bags-heading" class="text-sm font-semibold text-neutral-100">{{ __('Settings') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">
            {{ __('Typed knobs at three scopes. Read order: database → .env → built-in default. Saving writes to the database only; .env and defaults stay as-is.') }}
        </p>
    </header>

    @foreach ([
        'app' => ['title' => __('App (global)'), 'rows' => $this->appSchema, 'save' => 'saveApp',
                  'hint' => __('Applies to every user and household.')],
        'household' => ['title' => __(':name (household)', ['name' => $this->householdName]), 'rows' => $this->householdSchema, 'save' => 'saveHousehold',
                        'hint' => __('Applies to everyone in this household.')],
        'user' => ['title' => __('You (user)'), 'rows' => $this->userSchema, 'save' => 'saveUser',
                   'hint' => __('Applies only to you on this account.')],
    ] as $scope => $cfg)
        <details class="mb-3 rounded-md border border-neutral-800 bg-neutral-950/40 p-4 last:mb-0" @if ($scope === 'user') open @endif>
            <summary class="cursor-pointer text-xs font-medium text-neutral-200">
                {{ $cfg['title'] }}
                <span class="ml-1 text-[10px] font-normal text-neutral-500">— {{ $cfg['hint'] }}</span>
            </summary>

            @if ($cfg['rows'] === [])
                <p class="mt-3 text-[11px] text-neutral-500">{{ __('No settings declared for this scope yet.') }}</p>
            @else
                <div class="mt-3 space-y-3">
                    @foreach ($cfg['rows'] as $row)
                        @php
                            $key = (string) $row['key'];
                            $type = (string) ($row['type'] ?? 'string');
                            $label = (string) ($row['label'] ?? $key);
                            $description = (string) ($row['description'] ?? '');
                            $source = $sourceLabel($scope, $row);
                            $fieldId = "sb-{$scope}-{$key}";
                            $modelPath = "form.{$scope}.{$key}";
                        @endphp

                        <div class="rounded-md border border-neutral-800 bg-neutral-900/60 p-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <label for="{{ $fieldId }}" class="text-xs font-medium text-neutral-100">
                                    {{ $label }}
                                    <span class="ml-1 font-mono text-[10px] text-neutral-500">{{ $scope }}.{{ $key }}</span>
                                </label>
                                <span class="shrink-0 rounded-sm border border-neutral-800 bg-neutral-950 px-1.5 py-0.5 text-[9px] uppercase tracking-wider text-neutral-500">
                                    {{ $source }}
                                </span>
                            </div>

                            @switch($type)
                                @case('bool')
                                    <label class="mt-2 flex items-center gap-2 text-xs text-neutral-300">
                                        <input wire:model="{{ $modelPath }}" id="{{ $fieldId }}" type="checkbox"
                                               class="rounded border-neutral-700 bg-neutral-950">
                                        <span>{{ __('Enabled') }}</span>
                                    </label>
                                    @break
                                @case('enum')
                                    <select wire:model="{{ $modelPath }}" id="{{ $fieldId }}"
                                            class="mt-2 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                        @foreach ((array) ($row['options'] ?? []) as $optValue => $optLabel)
                                            <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                        @endforeach
                                    </select>
                                    @break
                                @case('int')
                                    <input wire:model="{{ $modelPath }}" id="{{ $fieldId }}" type="number" step="1"
                                           class="mt-2 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-xs tabular-nums text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                                    @break
                                @case('text')
                                    <textarea wire:model="{{ $modelPath }}" id="{{ $fieldId }}" rows="3"
                                              class="mt-2 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300"></textarea>
                                    @break
                                @case('url')
                                @case('email')
                                @default
                                    <input wire:model="{{ $modelPath }}" id="{{ $fieldId }}"
                                           type="{{ $type === 'url' ? 'url' : ($type === 'email' ? 'email' : 'text') }}"
                                           class="mt-2 w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-1.5 text-xs text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            @endswitch

                            @if ($description !== '')
                                <p class="mt-1 text-[11px] text-neutral-500">{{ $description }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex items-center justify-between gap-3">
                    <button type="button" wire:click="{{ $cfg['save'] }}"
                            class="rounded-md border border-neutral-700 bg-neutral-900 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-500 hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                        {{ __('Save :scope', ['scope' => $scope]) }}
                    </button>
                    @if ($statuses[$scope])
                        <div role="status" class="text-[11px] text-emerald-400">{{ $statuses[$scope] }}</div>
                    @endif
                </div>
            @endif
        </details>
    @endforeach
</section>
