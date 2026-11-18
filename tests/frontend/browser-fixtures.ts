import { router } from '@inertiajs/core';
import { vi } from 'vite-plus/test';

export const browserState = {
    key: 'catalog',
    version: 'a'.repeat(64),
    expiration: { minutes: 240 },
};

export function shareValue(source: string, state: unknown, version = browserState.version) {
    return 's1.' + Buffer.from(JSON.stringify({ source, version, state })).toString('base64url');
}

export function memoryStorage() {
    const values = new Map<string, string>();
    return {
        get length() {
            return values.size;
        },
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => {
            values.set(key, value);
        },
        removeItem: (key: string) => {
            values.delete(key);
        },
        key: (index: number) => Array.from(values.keys())[index] ?? null,
        clear: () => values.clear(),
    } satisfies Storage;
}

export function browserAt(href: string) {
    const location = { href };
    const storage = memoryStorage();
    vi.stubGlobal('window', Object.assign(new EventTarget(), { location, localStorage: storage }));
    vi.stubGlobal('document', new EventTarget());
    for (const method of ['push', 'replace'] as const)
        vi.spyOn(router, method).mockImplementation((options) => {
            location.href = String(options.url);
            const finish = Reflect.get(options, 'onFinish');
            if (typeof finish === 'function') Reflect.apply(finish, undefined, []);
        });
    return {
        location,
        storage,
        navigate: (to: string) => {
            location.href = to;
            window.dispatchEvent(new Event('popstate'));
            document.dispatchEvent(
                new CustomEvent('inertia:navigate', { detail: { page: { url: to } } }),
            );
        },
    };
}
