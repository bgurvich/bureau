// Theme resolver: reads <html data-theme>, resolves "system" against OS
// preference, exposes the result as <html data-resolved-theme> so CSS can
// react without flashing. Listens for the Livewire "theme-changed" event and
// for OS preference changes.

type ThemePref = 'light' | 'dark' | 'system' | 'retro';

interface LivewireGlobal {
    on(event: string, handler: (payload: unknown) => void): void;
    dispatch(event: string, payload?: Record<string, unknown>): void;
}

interface AlpineGlobal {
    data(name: string, factory: (...args: unknown[]) => Record<string, unknown>): void;
}

declare global {
    interface Window {
        Livewire?: LivewireGlobal;
        Alpine?: AlpineGlobal;
    }
}

export {};

const root: HTMLElement = document.documentElement;
const media: MediaQueryList = window.matchMedia('(prefers-color-scheme: dark)');

function resolveTheme(pref: ThemePref): ThemePref {
    if (pref === 'system') {
        return media.matches ? 'dark' : 'light';
    }
    return pref;
}

function applyTheme(pref: ThemePref): void {
    const resolved = resolveTheme(pref);
    root.dataset.theme = pref;
    root.dataset.resolvedTheme = resolved;
    // Retro is a dark-based opt-in; keep the native color-scheme honest.
    root.style.colorScheme = resolved === 'retro' ? 'dark' : resolved;
}

applyTheme((root.dataset.theme as ThemePref) || 'system');

media.addEventListener('change', () => {
    if (((root.dataset.theme as ThemePref) || 'system') === 'system') {
        applyTheme('system');
    }
});

document.addEventListener('livewire:init', () => {
    window.Livewire?.on('theme-changed', (payload: unknown) => {
        const next = extractTheme(payload);
        applyTheme(next);
    });
});

function extractTheme(payload: unknown): ThemePref {
    if (payload && typeof payload === 'object') {
        const obj = payload as Record<string, unknown>;
        if (typeof obj.theme === 'string') return obj.theme as ThemePref;

        if (Array.isArray(payload) && payload[0] && typeof payload[0] === 'object') {
            const first = payload[0] as Record<string, unknown>;
            if (typeof first.theme === 'string') return first.theme as ThemePref;
        }
    }
    return 'system';
}

// Keyboard shortcuts for the Inspector.
//   .             — Inspector type picker
//   t n c x b a d m p h v i s — direct-to-type (task, note, contact, transaction, bill, account, document, meeting, project, property, vehicle, inventory, insurance)
//   \             — time tracker setup / stop
//   '             — alerts dropdown
// All skip when the user is typing so they don't hijack normal input.

const TYPE_SHORTCUTS: Record<string, string> = {
    t: 'task',
    n: 'note',
    c: 'contact',
    x: 'transaction',
    b: 'bill',
    a: 'account',
    d: 'document',
    m: 'meeting',
    p: 'project',
    h: 'property',
    v: 'vehicle',
    i: 'inventory',
    s: 'insurance',
    o: 'online_account',
};

const GLOBAL_SHORTCUTS: Record<string, () => void> = {
    '\\': handleTimerShortcut,
    '.': () => window.Livewire?.dispatch('inspector-open'),
    "'": () => window.dispatchEvent(new CustomEvent('alerts-open')),
};

function handleTimerShortcut(): void {
    const el = document.querySelector('[data-timer-status]');
    const status = el instanceof HTMLElement ? el.dataset.timerStatus ?? '' : '';

    if (status === 'running' || status === 'paused') {
        if (window.confirm('Stop timer and save the entry?')) {
            window.Livewire?.dispatch('timer-stop');
        }
        return;
    }

    window.Livewire?.dispatch('timer-open-setup');
}

function isEditableTarget(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.tagName === 'SELECT' ||
            target.isContentEditable)
    );
}

window.addEventListener('keydown', (event: KeyboardEvent) => {
    if (isEditableTarget(event.target)) return;

    // Skip modified shortcuts so we don't clash with browser combos.
    if (event.metaKey || event.ctrlKey || event.altKey) return;

    const globalAction = GLOBAL_SHORTCUTS[event.key];
    if (globalAction) {
        event.preventDefault();
        globalAction();
        return;
    }

    const type = TYPE_SHORTCUTS[event.key.toLowerCase()];
    if (type) {
        event.preventDefault();
        window.Livewire?.dispatch('inspector-open', { type });
    }
});

// Reusable Alpine component for searchable dropdowns. Binds to a Livewire
// property via $wire.set and reads the current value through $wire[model].
// Optional `allowCreate` + `createMethod` enables inline creation: unmatched
// typed values become a "+ Create 'X'" option that calls the server method
// and auto-selects the returned row via the `ss-option-added` event.
interface SearchableSelectConfig {
    model: string;
    options: Record<string, string>;
    placeholder: string;
    allowCreate?: boolean;
    createMethod?: string;
}

type SearchableSelectData = {
    search: string;
    open: boolean;
    active: number;
    options: Record<string, string>;
    placeholder: string;
    model: string;
    allowCreate: boolean;
    createMethod: string;
    creating: boolean;
    value: string;
    label: string;
    filtered: Array<[string, string]>;
    showCreateOption: boolean;
    placeholderCreateLabel: string;
    $wire: Record<string, unknown> & {
        set: (key: string, value: unknown) => void;
        [method: string]: unknown;
    };
    $refs: { search?: HTMLInputElement; trigger?: HTMLButtonElement };
    $nextTick: (cb: () => void) => void;
    toggle(): void;
    close(): void;
    pick(value: string): void;
    move(delta: number): void;
    activate(): void;
    createInline(): void;
};

document.addEventListener('alpine:init', () => {
    const alpine = window.Alpine;
    if (!alpine) return;

    alpine.data('searchableSelect', ((config: SearchableSelectConfig) => ({
        search: '',
        open: false,
        active: 0,
        options: { ...config.options },
        placeholder: config.placeholder,
        model: config.model,
        allowCreate: !!config.allowCreate,
        createMethod: config.createMethod ?? '',
        creating: false,
        placeholderCreateLabel: 'Create',
        get value(): string {
            const self = this as unknown as SearchableSelectData;
            return String(self.$wire[self.model] ?? '');
        },
        get label(): string {
            const self = this as unknown as SearchableSelectData;
            return self.options[self.value] ?? '';
        },
        get filtered(): Array<[string, string]> {
            const self = this as unknown as SearchableSelectData;
            const q = self.search.toLowerCase();
            return Object.entries(self.options).filter(([, lbl]) =>
                String(lbl).toLowerCase().includes(q),
            );
        },
        get showCreateOption(): boolean {
            const self = this as unknown as SearchableSelectData;
            if (!self.allowCreate || !self.createMethod) return false;
            const q = self.search.trim();
            if (!q) return false;
            const exact = Object.values(self.options).some(
                (lbl) => String(lbl).toLowerCase() === q.toLowerCase(),
            );
            return !exact;
        },
        init(this: SearchableSelectData): void {
            const self = this;
            window.Livewire?.on('ss-option-added', (payload: unknown) => {
                const data = normalizeSsPayload(payload);
                if (!data || data.model !== self.model) return;
                self.options[String(data.id)] = String(data.label);
                self.$wire.set(self.model, data.id);
                self.open = false;
                self.search = '';
                self.creating = false;
                self.$nextTick(() => self.$refs.trigger?.focus());
            });
        },
        toggle(this: SearchableSelectData): void {
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.active = 0;
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },
        close(this: SearchableSelectData): void {
            if (!this.open) return;
            this.open = false;
            this.search = '';
        },
        pick(this: SearchableSelectData, value: string): void {
            this.$wire.set(this.model, value);
            this.open = false;
            this.search = '';
            this.$nextTick(() => this.$refs.trigger?.focus());
        },
        move(this: SearchableSelectData, delta: number): void {
            const total = this.filtered.length + (this.showCreateOption ? 1 : 0);
            if (total === 0) return;
            this.active = (this.active + delta + total) % total;
        },
        activate(this: SearchableSelectData): void {
            const hit = this.filtered[this.active];
            if (hit) {
                this.pick(hit[0]);
                return;
            }
            if (this.showCreateOption) {
                this.createInline();
            }
        },
        createInline(this: SearchableSelectData): void {
            if (!this.showCreateOption || this.creating) return;
            const name = this.search.trim();
            const fn = this.$wire[this.createMethod];
            if (typeof fn !== 'function') return;
            this.creating = true;
            Promise.resolve((fn as (...a: unknown[]) => unknown).call(this.$wire, name)).catch(() => {
                this.creating = false;
            });
        },
    })) as unknown as (...args: unknown[]) => Record<string, unknown>);
});

function normalizeSsPayload(payload: unknown): { model: string; id: string | number; label: string } | null {
    const raw = Array.isArray(payload) ? payload[0] : payload;
    if (!raw || typeof raw !== 'object') return null;
    const obj = raw as Record<string, unknown>;
    if (typeof obj.model !== 'string' || obj.id === undefined || obj.id === null) return null;
    return {
        model: obj.model,
        id: obj.id as string | number,
        label: String(obj.label ?? ''),
    };
}
