import { object } from './protocol.js';
import {
    exact,
    parseRequestedV2,
    type AdapterDefinition,
    type BrowserStatePolicy,
    type SourceRequestedStateV2,
} from './protocol-v2.js';

const prefix = 'dapter:state:';
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function deadline(policy: BrowserStatePolicy, now: number): number {
    return 'at' in policy.expiration
        ? policy.expiration.at
        : Math.min(Number.MAX_SAFE_INTEGER, now + policy.expiration.minutes * 60_000);
}

function portable(value: string): unknown {
    const encoded = value.slice(3);
    if (!/^[A-Za-z0-9_-]+$/.test(encoded)) throw new Error('Invalid portable state');
    const bytes = Uint8Array.from(atob(encoded.replace(/-/g, '+').replace(/_/g, '/')), (char) =>
        char.charCodeAt(0),
    );
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
}

export function shareToken(source: string, version: string, state: SourceRequestedStateV2): string {
    const bytes = new TextEncoder().encode(JSON.stringify({ source, version, state }));
    const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join('');
    return 's1.' + btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function readSnapshot(
    raw: string | null,
    source: string,
    policy: BrowserStatePolicy,
    definition: AdapterDefinition,
): SourceRequestedStateV2 | null {
    const now = Date.now();
    if (!raw || now >= deadline(policy, now)) return null;
    try {
        let record: Record<string, unknown>;
        if (raw.startsWith('s1.')) {
            record = object(portable(raw));
            exact(record, ['source', 'version', 'state']);
        } else {
            if (!uuid.test(raw)) return null;
            record = object(JSON.parse(window.localStorage.getItem(prefix + raw) ?? 'null'));
            exact(record, ['source', 'version', 'state', 'expiresAt']);
            if (
                typeof record.expiresAt !== 'number' ||
                !Number.isSafeInteger(record.expiresAt) ||
                record.expiresAt <= now
            )
                return null;
        }
        return record.source === source && record.version === policy.version
            ? parseRequestedV2(record.state, definition)
            : null;
    } catch {
        return null;
    }
}

function newId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    // getRandomValues is available on HTTP development origins too.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6]! & 0x0f) | 0x40;
    bytes[8] = (bytes[8]! & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

export function writeSnapshot(
    source: string,
    policy: BrowserStatePolicy,
    state: SourceRequestedStateV2,
): string | null {
    const now = Date.now();
    const expiresAt = deadline(policy, now);
    if (expiresAt <= now) return null;
    try {
        sweepSnapshots(source, policy);
        const id = newId();
        window.localStorage.setItem(
            prefix + id,
            JSON.stringify({ source, version: policy.version, state, expiresAt }),
        );
        return id;
    } catch {
        return null;
    }
}

export function removeSnapshot(id: string | null, source: string): void {
    if (!id || !uuid.test(id)) return;
    try {
        const store = window.localStorage;
        if (object(JSON.parse(store.getItem(prefix + id) ?? 'null')).source === source)
            store.removeItem(prefix + id);
    } catch {
        // Browser storage may be disabled or evicted.
    }
}

export function sweepSnapshots(source: string, policy: BrowserStatePolicy): void {
    try {
        const store = window.localStorage;
        const keys = Array.from({ length: store.length }, (_, i) => store.key(i));
        const now = Date.now();
        for (const key of keys) {
            if (!key?.startsWith(prefix) || !uuid.test(key.slice(prefix.length))) continue;
            try {
                const record = object(JSON.parse(store.getItem(key) ?? 'null'));
                if (
                    record.source === source &&
                    (record.version !== policy.version ||
                        typeof record.expiresAt !== 'number' ||
                        !Number.isSafeInteger(record.expiresAt) ||
                        record.expiresAt <= now ||
                        deadline(policy, now) <= now)
                )
                    store.removeItem(key);
            } catch {}
        }
    } catch {}
}
