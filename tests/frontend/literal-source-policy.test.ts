import { http, router, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import type { AdapterDefinition } from '@artisan-toolbox/dapter';
import { parseDefinition } from '../../resources/js/protocol-v2.js';
import { definition, intent, mount, page, settle } from './phase2-fixtures.js';

const literalDefinition = {
    ...definition,
    fields: {
        ...definition.fields,
        name: {
            ...definition.fields.name,
            search: {
                valueType: 'string',
                matchTypes: ['contains', 'equals', 'starts_with', 'ends_with'],
            },
        },
    },
    global: {
        ...definition.global,
        search: {
            valueType: 'string',
            targets: { name: { valueType: 'string', matchType: 'contains' } },
        },
    },
} as const satisfies AdapterDefinition;
const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (state = intent()) => ({
    status: 200,
    data: JSON.stringify(page(state, literalDefinition)),
    headers: {},
});
const sent = () => JSON.parse(String(request.mock.calls.at(-1)?.[0].data));
function create(...args: Parameters<typeof mount>) {
    const mounted = mount(...args);
    cleanups.push(mounted.unmount);
    return mounted;
}
beforeEach(() => {
    request.mockReset();
    http.setClient({ request });
    vi.stubGlobal('Ziggy', {
        url: 'https://example.test',
        port: null,
        defaults: {},
        routes: { 'dapter.data': { uri: 'dapter/data/{source}', methods: ['POST'] } },
    });
});
afterEach(() => {
    for (const cleanup of cleanups.splice(0)) cleanup();
    http.setClient(original);
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it.each(['JoÃo', 'Joa\u0303o', '%_!\\'])(
    'sends the original literal %j through every field operator and fixed global search',
    async (value) => {
        request.mockResolvedValueOnce(respond());
        const { handle } = create();
        await settle();
        for (const matchType of literalDefinition.fields.name.search.matchTypes) {
            const expected = intent({ fields: { name: { search: { value, matchType } } } });
            request.mockResolvedValueOnce(respond(expected));
            expect(await handle.fields?.name?.search?.call({ value, matchType })).toEqual({
                status: 'success',
            });
            expect(sent()).toEqual({ version: 2, state: expected });
            expect(handle.fields?.name?.search?.current).toEqual({ value, matchType });
        }
        const expected = intent({
            fields: { name: { search: { value, matchType: 'ends_with' } } },
            global: { search: { value } },
        });
        request.mockResolvedValueOnce(respond(expected));
        expect(await handle.global?.search?.call({ value })).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: expected });
        expect(handle.global?.search?.current).toEqual({ value });
    },
);

it.each(['strict', 'strictLiteralSearch', 'strict_literal_search', 'literalSearchPolicy'])(
    'rejects a forged %s at operation and replacement boundaries before HTTP',
    async (key) => {
        request.mockResolvedValueOnce(respond());
        const { handle } = create();
        await settle();
        const before = handle.requested;
        const field = { value: 'JoÃo', matchType: 'contains', [key]: true } as const;
        const global = { value: 'JoÃo', [key]: false };
        for (const result of [
            await handle.fields?.name?.search?.call.apply(undefined, [field]),
            await handle.global?.search?.call.apply(undefined, [global]),
            await handle.fields?.name?.search?.call(
                { value: 'JoÃo', matchType: 'contains' },
                {
                    [key]: true,
                },
            ),
            await handle.replaceState(intent({ fields: { name: { search: field } } })),
            await handle.replaceState(intent({ global: { search: global } })),
            await handle.replaceState({ ...intent(), [key]: true }),
        ])
            expect(result).toMatchObject({ status: 'failure', error: { kind: 'validation' } });
        expect(handle.requested).toBe(before);
        expect(request).toHaveBeenCalledTimes(1);
        expect(() => parseDefinition({ ...literalDefinition, [key]: true })).toThrow();
        expect(() =>
            parseDefinition({
                ...literalDefinition,
                fields: {
                    ...literalDefinition.fields,
                    name: {
                        ...literalDefinition.fields.name,
                        search: { ...literalDefinition.fields.name.search, [key]: true },
                    },
                },
            }),
        ).toThrow();
    },
);

it('preserves original literal text through debounce and clears immediately', async () => {
    vi.useFakeTimers();
    request.mockResolvedValueOnce(respond());
    const { handle } = create({ searchDebounce: 300 });
    await settle();
    const value = 'JoA\u0303o%_!\\';
    const expected = intent({ fields: { name: { search: { value, matchType: 'contains' } } } });
    request.mockResolvedValueOnce(respond(expected));
    const pending = handle.fields?.name?.search?.call({ value, matchType: 'contains' });
    expect(handle.requested).toEqual(expected);
    expect(request).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(300);
    expect(await pending).toEqual({ status: 'success' });
    expect(sent()).toEqual({ version: 2, state: expected });
    request.mockResolvedValueOnce(respond());
    expect(await handle.fields?.name?.search?.clear()).toEqual({ status: 'success' });
    expect(sent()).toEqual({ version: 2, state: intent() });
    expect(request).toHaveBeenCalledTimes(3);
});

it('restores original field and global text from its URL namespace', async () => {
    const value = 'JoA\u0303o%_!\\';
    const restored = intent({
        fields: { name: { search: { value, matchType: 'ends_with' } } },
        global: { search: { value } },
    });
    const url = new URL('https://example.test/catalog?other=kept#rows');
    url.searchParams.set(
        'dapter[literal-policy]',
        JSON.stringify({ version: 2, source: 'Products', state: restored }),
    );
    const location = { href: url.href };
    vi.stubGlobal('window', Object.assign(new EventTarget(), { location }));
    vi.stubGlobal('document', new EventTarget());
    vi.spyOn(router, 'replace').mockImplementation((options) => {
        location.href = String(options.url);
        const onFinish = Reflect.get(options, 'onFinish');
        if (typeof onFinish === 'function') Reflect.apply(onFinish, undefined, []);
    });
    request.mockResolvedValueOnce(respond(restored));
    const { handle } = create({ id: 'literal-policy', url: true });
    await settle();
    expect(handle.ready).toBe(true);
    expect(sent()).toEqual({ version: 2, state: restored });
    expect(handle.requested).toEqual(restored);
    expect(new URL(location.href).searchParams.get('other')).toBe('kept');
    expect(new URL(location.href).hash).toBe('#rows');
});
