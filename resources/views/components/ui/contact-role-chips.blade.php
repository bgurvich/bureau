@props([
    'roles' => [],
    'active' => null,
])

@php
    $slugs = array_values(array_filter((array) $roles, fn ($v) => is_string($v) && $v !== ''));
    $labels = App\Support\Enums::contactRoles();
@endphp

@if ($slugs !== [])
    <div {{ $attributes->class(['flex flex-wrap gap-1']) }}>
        @foreach ($slugs as $slug)
            @php
                $label = $labels[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
                $isActive = $active !== null && $active === $slug;
                // Emergency contacts get rose so they stand out in any list;
                // professional services get blue; personal warm-neutral.
                $cls = match (true) {
                    $slug === 'emergency_contact' => 'bg-rose-900/40 text-rose-200',
                    in_array($slug, ['doctor', 'lawyer', 'accountant', 'financial_advisor', 'contractor', 'mechanic'], true) => 'bg-sky-900/30 text-sky-200',
                    in_array($slug, ['landlord', 'tenant', 'roommate'], true) => 'bg-amber-900/30 text-amber-200',
                    default => 'bg-neutral-800 text-neutral-300',
                };
                $activeCls = $isActive ? ' ring-1 ring-emerald-500/70' : '';
            @endphp
            <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] {{ $cls }}{{ $activeCls }}"
                  title="{{ $label }}">
                {{ $label }}
            </span>
        @endforeach
    </div>
@else
    <span class="text-neutral-600">—</span>
@endif
