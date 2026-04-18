@php
    // 24x24 viewBox, stroke=currentColor, Lucide-style. Kept inline so icons
    // inherit text color automatically and swap cleanly across themes.
    $common = 'h-4 w-4 shrink-0';
    $stroke = 'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"';
@endphp
<svg class="{{ $common }}" viewBox="0 0 24 24" aria-hidden="true" {!! $stroke !!}>
    @switch($name ?? '')
        @case('home')
            <path d="M3 12 12 3l9 9"/>
            <path d="M5 10v10h4v-6h6v6h4V10"/>
            @break

        @case('wallet')
            <path d="M3 7h18v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>
            <path d="M3 7V5a2 2 0 0 1 2-2h11"/>
            <circle cx="17" cy="14" r="1.1"/>
            @break

        @case('swap')
            <path d="m8 3-4 4 4 4"/>
            <path d="M4 7h16"/>
            <path d="m16 21 4-4-4-4"/>
            <path d="M20 17H4"/>
            @break

        @case('receipt')
            <path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2-3 2Z"/>
            <path d="M8 10h8"/>
            <path d="M8 14h5"/>
            @break

        @case('check-square')
            <path d="m9 11 3 3L22 4"/>
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            @break

        @case('calendar')
            <path d="M8 2v4"/>
            <path d="M16 2v4"/>
            <path d="M3 10h18"/>
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            @break

        @case('user')
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
            @break

        @case('folder')
            <path d="M4 4h5l2 3h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/>
            @break

        @case('clock')
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 6v6l4 2"/>
            @break

        @case('file-signature')
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/>
            <path d="M14 2v6h6"/>
            <path d="m8 13 3 3 5-5"/>
            @break

        @case('shield')
            <path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6l-8-4Z"/>
            @break

        @case('building')
            <rect x="4" y="2" width="16" height="20" rx="2"/>
            <path d="M9 6h1"/>
            <path d="M13 6h1"/>
            <path d="M9 10h1"/>
            <path d="M13 10h1"/>
            <path d="M9 14h1"/>
            <path d="M13 14h1"/>
            <path d="M10 22v-4h4v4"/>
            @break

        @case('car')
            <circle cx="7" cy="18" r="2"/>
            <circle cx="17" cy="18" r="2"/>
            <path d="M5 18H3v-5l2-5h14l2 5v5h-2"/>
            <path d="M7 10h10"/>
            @break

        @case('box')
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
            <path d="M3.3 7 12 12l8.7-5"/>
            <path d="M12 22V12"/>
            @break

        @case('file-text')
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/>
            <path d="M14 2v6h6"/>
            <path d="M8 13h8"/>
            <path d="M8 17h8"/>
            <path d="M8 9h2"/>
            @break

        @case('image')
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <circle cx="9" cy="10" r="2"/>
            <path d="m21 15-5-5L5 21"/>
            @break

        @case('note')
            <path d="M16 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8l-5-5Z"/>
            <path d="M16 3v5h5"/>
            @break

        @case('stethoscope')
            <path d="M4 3v5a4 4 0 0 0 8 0V3"/>
            <path d="M6 3H4"/>
            <path d="M12 3h-2"/>
            <path d="M8 12v4a4 4 0 0 0 8 0v-2"/>
            <circle cx="18" cy="12" r="2"/>
            @break

        @case('pill')
            <path d="M10.5 20a6.5 6.5 0 1 1 9-9l-9 9Z"/>
            <path d="m8.5 8.5 7 7"/>
            @break

        @case('calendar-clock')
            <path d="M21 10V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7"/>
            <path d="M8 2v4"/>
            <path d="M16 2v4"/>
            <path d="M3 10h18"/>
            <circle cx="17" cy="17" r="4"/>
            <path d="M17 15v2l1 1"/>
            @break

        @case('key')
            <circle cx="7.5" cy="15.5" r="4"/>
            <path d="m10.5 12.5 10-10"/>
            <path d="m17 5.5 3 3"/>
            <path d="m15 9.5 3 3"/>
            @break

        @case('pie')
            <path d="M21 12a9 9 0 1 1-9-9v9h9Z"/>
            <path d="M13 3a9 9 0 0 1 8 8h-8V3Z"/>
            @break

        @default
            <circle cx="12" cy="12" r="3"/>
    @endswitch
</svg>
