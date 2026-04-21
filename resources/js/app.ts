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
//   t n c x b a d m p h v i s o r e g u l — direct-to-type
//     (task, note, contact, transaction, bill, account, document, meeting,
//      project, property, vehicle, inventory, insurance, online_account,
//      reminder, appointment [E=event], savings_goal, subscription,
//      time_entry [L=log])
//   \             — time tracker setup / stop
//   '             — alerts dropdown
// All skip when the user is typing so they don't hijack normal input.
// Keep this map in sync with resources/views/partials/inspector/type-picker.blade.php.

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
    r: 'reminder',
    e: 'appointment',
    g: 'savings_goal',
    u: 'subscription',
    l: 'time_entry',
    k: 'checklist_template',
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

// Live-reorder sortable list for repeaters (checklist items, and any
// future <x-ui.sortable-list> caller). Rows live in DOM order; dragstart
// on a handle inside a row starts the drag, dragover on the <ul>
// rearranges DOM nodes under the cursor in real time, drop commits the
// new key order to Livewire. If the user drops outside the list, the UL's
// dragend restores the snapshot taken at dragstart.
//
// Requires each row to carry `data-item-key` and a `draggable="true"`
// handle that calls `onDragStart(key, $event)`. The `<ul>` must carry
// `data-reorder-method="..."` naming the Livewire method that accepts
// `array<int, string>` of row keys — the `<x-ui.sortable-list>` Blade
// component handles this wiring.
type SortableListData = {
    dragFrom: string | null;
    committed: boolean;
    snapshot: Element[];
    $el: HTMLElement;
    $wire: Record<string, unknown>;
    onDragStart(key: string | number, evt: DragEvent): void;
    onDragOver(evt: DragEvent): void;
    onDrop(): void;
    onDragEnd(): void;
};

document.addEventListener('alpine:init', () => {
    const alpine = window.Alpine;
    if (!alpine) return;

    alpine.data('checklistItemsSortable', (() => ({
        dragFrom: null as string | null,
        committed: false,
        snapshot: [] as Element[],
        onDragStart(this: SortableListData, key: string | number, evt: DragEvent): void {
            // Keys are always strings internally so int IDs (e.g. media.id)
            // compare equal to the template's `@js($k)` output regardless of
            // whether Blade emitted a numeric or quoted literal.
            const k = String(key);
            this.dragFrom = k;
            this.committed = false;
            this.snapshot = Array.from(this.$el.querySelectorAll('[data-item-key]'));
            if (evt.dataTransfer) {
                evt.dataTransfer.effectAllowed = 'move';
                // Firefox requires setData for `drop` to fire at all.
                evt.dataTransfer.setData('text/plain', k);
                // Use the row as the drag image so the user sees the whole
                // item follow the cursor, not just the tiny handle.
                const row = (evt.target as HTMLElement | null)?.closest<HTMLElement>('[data-item-key]');
                if (row) evt.dataTransfer.setDragImage(row, 20, 20);
            }
        },
        onDragOver(this: SortableListData, evt: DragEvent): void {
            if (this.dragFrom === null) return;
            const target = (evt.target as HTMLElement | null)?.closest<HTMLElement>('[data-item-key]');
            if (!target) return;
            const targetKey = target.getAttribute('data-item-key');
            if (targetKey === this.dragFrom) return;
            const dragEl = this.$el.querySelector<HTMLElement>(
                `[data-item-key="${CSS.escape(this.dragFrom)}"]`,
            );
            if (!dragEl || dragEl === target) return;
            const rect = target.getBoundingClientRect();
            const before = evt.clientY < rect.top + rect.height / 2;
            if (before) {
                target.parentNode?.insertBefore(dragEl, target);
            } else {
                target.parentNode?.insertBefore(dragEl, target.nextSibling);
            }
        },
        onDrop(this: SortableListData): void {
            if (this.dragFrom === null) return;
            this.committed = true;
            const order = Array.from(this.$el.querySelectorAll('[data-item-key]'))
                .map((el) => el.getAttribute('data-item-key') ?? '')
                .filter((k) => k !== '');
            // `data-reorder-method` on the <ul> names the Livewire method
            // to invoke. Falls back to reorderItems so callers that don't
            // set the attribute still work (mirrors the original hardcoded
            // name for the checklist-items repeater).
            const methodName = this.$el.dataset.reorderMethod || 'reorderItems';
            const method = this.$wire[methodName];
            if (typeof method === 'function') {
                (method as (...a: unknown[]) => unknown).call(this.$wire, order);
            }
            this.dragFrom = null;
        },
        onDragEnd(this: SortableListData): void {
            if (!this.committed && this.snapshot.length) {
                const parent = this.snapshot[0].parentNode;
                if (parent) this.snapshot.forEach((el) => parent.appendChild(el));
            }
            this.dragFrom = null;
            this.committed = false;
            this.snapshot = [];
        },
    })) as unknown as (...args: unknown[]) => Record<string, unknown>);

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
            // Pass the binding's model name so the server method can dispatch
            // `ss-option-added` back to *this* select instead of only the
            // default/legacy field name — otherwise pickers like
            // subscription_counterparty_id never receive the update.
            Promise.resolve(
                (fn as (...a: unknown[]) => unknown).call(this.$wire, name, this.model),
            ).catch(() => {
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

// ────────────────────────────────────────────────────────────────────────
// WebAuthn (passkey) helpers + Alpine components
//
// The server ceremony is implemented by laragear/webauthn and exchanges
// PublicKeyCredentialCreationOptions / RequestOptions. We must convert
// the base64url fields the server emits into ArrayBuffers for the
// browser WebAuthn API, then convert the authenticator response fields
// back to base64url strings for the server to verify.
// ────────────────────────────────────────────────────────────────────────

function base64UrlToBuffer(value: string): ArrayBuffer {
    const pad = (4 - (value.length % 4)) % 4;
    const b64 = (value + '='.repeat(pad)).replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
}

function bufferToBase64Url(buf: ArrayBuffer | null): string {
    if (!buf) return '';
    const bytes = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function csrfToken(): string {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el instanceof HTMLMetaElement ? el.content : '';
}

async function postJson(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body ?? {}),
    });
}

interface PublicKeyOptions {
    challenge: string;
    user?: { id: string; name: string; displayName: string };
    pubKeyCredParams?: unknown;
    rp?: unknown;
    timeout?: number;
    excludeCredentials?: Array<{ id: string; type: string; transports?: string[] }>;
    allowCredentials?: Array<{ id: string; type: string; transports?: string[] }>;
    authenticatorSelection?: unknown;
    attestation?: string;
    extensions?: unknown;
    userVerification?: string;
    rpId?: string;
}

function decodeCreationOptions(src: PublicKeyOptions): PublicKeyCredentialCreationOptions {
    return {
        ...(src as unknown as PublicKeyCredentialCreationOptions),
        challenge: base64UrlToBuffer(src.challenge),
        user: {
            ...(src.user as PublicKeyCredentialUserEntity),
            id: base64UrlToBuffer(src.user!.id),
        },
        excludeCredentials: (src.excludeCredentials ?? []).map((c) => ({
            id: base64UrlToBuffer(c.id),
            type: c.type as PublicKeyCredentialType,
            transports: c.transports as AuthenticatorTransport[] | undefined,
        })),
    };
}

function decodeRequestOptions(src: PublicKeyOptions): PublicKeyCredentialRequestOptions {
    return {
        ...(src as unknown as PublicKeyCredentialRequestOptions),
        challenge: base64UrlToBuffer(src.challenge),
        allowCredentials: (src.allowCredentials ?? []).map((c) => ({
            id: base64UrlToBuffer(c.id),
            type: c.type as PublicKeyCredentialType,
            transports: c.transports as AuthenticatorTransport[] | undefined,
        })),
    };
}

function encodeAttestation(cred: PublicKeyCredential): Record<string, unknown> {
    const r = cred.response as AuthenticatorAttestationResponse;
    return {
        id: cred.id,
        rawId: bufferToBase64Url(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bufferToBase64Url(r.clientDataJSON),
            attestationObject: bufferToBase64Url(r.attestationObject),
        },
    };
}

function encodeAssertion(cred: PublicKeyCredential): Record<string, unknown> {
    const r = cred.response as AuthenticatorAssertionResponse;
    return {
        id: cred.id,
        rawId: bufferToBase64Url(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bufferToBase64Url(r.clientDataJSON),
            authenticatorData: bufferToBase64Url(r.authenticatorData),
            signature: bufferToBase64Url(r.signature),
            userHandle: r.userHandle ? bufferToBase64Url(r.userHandle) : null,
        },
    };
}

document.addEventListener('alpine:init', () => {
    const alpine = window.Alpine;
    if (!alpine) return;

    // Best-effort browser + OS guess from the user agent. Used to pre-fill the
    // passkey alias field so registered credentials have distinct, recognisable
    // names out of the box instead of a generic "Security key". User can still
    // override before submitting.
    const guessDeviceLabel = (): string => {
        const ua = typeof navigator !== 'undefined' ? navigator.userAgent || '' : '';
        const platform =
            /iPhone/.test(ua) ? 'iPhone' :
            /iPad/.test(ua) ? 'iPad' :
            /Android/.test(ua) ? 'Android' :
            /Mac OS X/.test(ua) ? 'Mac' :
            /Windows/.test(ua) ? 'Windows' :
            /Linux/.test(ua) ? 'Linux' : 'device';
        const browser =
            /Edg\//.test(ua) ? 'Edge' :
            /OPR\//.test(ua) ? 'Opera' :
            /Firefox/.test(ua) ? 'Firefox' :
            /Chrome/.test(ua) ? 'Chrome' :
            /Safari/.test(ua) ? 'Safari' : '';
        return browser ? `${browser} on ${platform}` : platform;
    };

    alpine.data('passkeyManager', (() => ({
        alias: guessDeviceLabel(),
        busy: false,
        error: '',
        notice: '',
        async enroll(this: { alias: string; busy: boolean; error: string; notice: string }): Promise<void> {
            if (!('credentials' in navigator)) {
                this.error = 'This browser does not support passkeys.';
                return;
            }
            this.busy = true;
            this.error = '';
            this.notice = '';
            try {
                const optsRes = await postJson('/webauthn/register/options', {});
                if (!optsRes.ok) throw new Error('Could not start passkey registration.');
                const opts = (await optsRes.json()) as PublicKeyOptions;
                const cred = (await navigator.credentials.create({
                    publicKey: decodeCreationOptions(opts),
                })) as PublicKeyCredential | null;
                if (!cred) throw new Error('Registration was cancelled.');
                const body = { ...encodeAttestation(cred), alias: this.alias || '' };
                const saveRes = await postJson('/webauthn/register', body);
                if (!saveRes.ok) throw new Error('Server rejected the new passkey.');
                this.notice = 'Passkey registered.';
                // Reset the alias to the guessed label, not empty, so a second
                // registration on the same machine (e.g. adding a platform key
                // after a password-manager passkey) has a sensible starting name.
                this.alias = guessDeviceLabel();
                window.dispatchEvent(new CustomEvent('passkey-enrolled'));
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Passkey registration failed.';
            } finally {
                this.busy = false;
            }
        },
    })) as unknown as (...args: unknown[]) => Record<string, unknown>);

    alpine.data('passkeyLogin', (() => ({
        busy: false,
        error: '',
        async signIn(this: { busy: boolean; error: string }): Promise<void> {
            if (!('credentials' in navigator)) {
                this.error = 'This browser does not support passkeys.';
                return;
            }
            this.busy = true;
            this.error = '';
            try {
                const optsRes = await postJson('/webauthn/login/options', {});
                if (!optsRes.ok) throw new Error('Could not start passkey sign-in.');
                const opts = (await optsRes.json()) as PublicKeyOptions;
                const cred = (await navigator.credentials.get({
                    publicKey: decodeRequestOptions(opts),
                })) as PublicKeyCredential | null;
                if (!cred) throw new Error('Sign-in was cancelled.');
                const loginRes = await postJson('/webauthn/login', encodeAssertion(cred));
                if (!loginRes.ok) throw new Error('That passkey isn\u2019t recognized on this account.');
                const body = (await loginRes.json()) as { redirect?: string };
                window.location.assign(body.redirect ?? '/');
            } catch (err) {
                this.error = err instanceof Error ? err.message : 'Passkey sign-in failed.';
            } finally {
                this.busy = false;
            }
        },
    })) as unknown as (...args: unknown[]) => Record<string, unknown>);

    // The address-autocomplete field partial expects this factory to exist —
    // the prior implementation registered it dynamically from the Blade
    // partial, which is brittle. Centralize it here so the helper is always
    // available on pages that include the field.
    alpine.data(
        'addressAutocomplete',
        ((config: { url: string; eventName: string; minChars?: number }) => ({
            query: '',
            open: false,
            loading: false,
            suggestions: [] as Array<Record<string, string>>,
            activeIndex: -1,
            token: 0,
            async onInput(this: {
                query: string;
                open: boolean;
                loading: boolean;
                suggestions: Array<Record<string, string>>;
                activeIndex: number;
                token: number;
            }): Promise<void> {
                const q = this.query.trim();
                if (q.length < (config.minChars ?? 3)) {
                    this.suggestions = [];
                    this.open = false;
                    return;
                }
                this.loading = true;
                const myToken = ++this.token;
                try {
                    const url = `${config.url}?q=${encodeURIComponent(q)}`;
                    const res = await fetch(url, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error('autocomplete request failed');
                    const body = (await res.json()) as { results?: Array<Record<string, string>> };
                    if (this.token !== myToken) return; // stale
                    this.suggestions = body.results ?? [];
                    this.activeIndex = this.suggestions.length ? 0 : -1;
                    this.open = this.suggestions.length > 0;
                } finally {
                    this.loading = false;
                }
            },
            move(this: { activeIndex: number; suggestions: unknown[]; open: boolean }, delta: number): void {
                if (!this.suggestions.length) return;
                this.open = true;
                const len = this.suggestions.length;
                this.activeIndex = (this.activeIndex + delta + len) % len;
            },
            activate(this: { activeIndex: number; suggestions: unknown[]; pick: (i: number) => void }): void {
                if (this.activeIndex >= 0 && this.activeIndex < this.suggestions.length) {
                    this.pick(this.activeIndex);
                }
            },
            pick(
                this: {
                    suggestions: Array<Record<string, string>>;
                    query: string;
                    open: boolean;
                    activeIndex: number;
                },
                i: number,
            ): void {
                const s = this.suggestions[i];
                if (!s) return;
                this.query = s.formatted ?? '';
                this.open = false;
                this.activeIndex = -1;
                window.dispatchEvent(new CustomEvent(config.eventName, { detail: { suggestion: s } }));
            },
            close(this: { open: boolean; activeIndex: number }): void {
                this.open = false;
                this.activeIndex = -1;
            },
        })) as unknown as (...args: unknown[]) => Record<string, unknown>,
    );
});

// PWA service worker registration — previously an inline <script> in the
// mobile layout. Deferred to 'load' so it can't delay first paint.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
