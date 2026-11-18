import { http, HttpNetworkError, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { defineComponent, h, ref, watch } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    useDataAdapter,
    type AdapterDefinition,
    type DataAdapterHandle,
} from '@artisan-toolbox/dapter';
import { definition, deferred, intent, mount, mountTree, page, settle } from './phase2-fixtures.js';

const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
function create(...args: Parameters<typeof mount>) {
    const result = mount(...args);
    cleanups.push(result.unmount);
    return result;
}
function search(handle: DataAdapterHandle) {
    const capability = handle.fields?.name?.search;
    if (!capability) throw new Error('Missing search');
    return capability;
}
function order(handle: DataAdapterHandle) {
    const capability = handle.fields?.name?.order;
    if (!capability) throw new Error('Missing order');
    return capability;
}
function filter(handle: DataAdapterHandle) {
    const capability = handle.fields?.status?.filters?.selected;
    if (!capability) throw new Error('Missing filter');
    return capability;
}
function sent(index = request.mock.calls.length - 1): unknown {
    return JSON.parse(String(request.mock.calls[index]?.[0].data));
}
beforeEach(() => {
    request.mockReset();
    http.setClient({ request });
    Object.defineProperty(globalThis, 'Ziggy', {
        configurable: true,
        value: {
            url: 'https://example.test',
            port: null,
            defaults: {},
            routes: { 'dapter.data': { uri: 'dapter/data/{source}', methods: ['POST'] } },
        },
    });
});
afterEach(() => {
    for (const cleanup of cleanups.splice(0)) cleanup();
    vi.useRealTimers();
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
});

describe('protocol 2 public controller', () => {
    it('projects decoded page size options without advertising removed values', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create({
            decodeDefinition(value) {
                return { ...value, paging: { ...value.paging, per_page_options: [2] } };
            },
        });
        await settle();
        expect(handle.ready).toBe(true);
        expect(handle.definition?.paging.per_page_options).toEqual([2]);
        expect(handle.paging.per_page.options).toEqual([2]);
        expect(await handle.paging.per_page.setValue(3)).toMatchObject({
            status: 'failure',
            error: { kind: 'validation' },
        });
        expect(await handle.paging.per_page.setValue(2)).toEqual({ status: 'success' });
        expect(request).toHaveBeenCalledTimes(1);
    });
    it.each(['__proto__', 'constructor', 'toString'])(
        'supports legal field and filter identifier %s without phantom capabilities',
        async (name) => {
            const capabilities = {
                ...definition,
                fields: Object.fromEntries([
                    ...Object.entries(definition.fields),
                    ...['__proto__', 'constructor', 'toString'].map((key) => [
                        key,
                        {
                            search: definition.fields.name.search,
                            order: definition.fields.name.order,
                            filters: Object.fromEntries(
                                ['__proto__', 'constructor', 'toString'].map((id) => [
                                    id,
                                    definition.fields.status.filters.selected,
                                ]),
                            ),
                        },
                    ]),
                ]),
            } satisfies AdapterDefinition;
            const unrelated = {
                name: { search: { value: 'Ada', matchType: 'contains' } },
                status: { filters: { selected: { value: false } } },
            } as const;
            const initial = intent({
                fields: unrelated,
                paging: { mode: 'offset', current: 2, per_page: 3 },
            });
            request.mockResolvedValueOnce(respond(page(initial, capabilities)));
            const { handle } = create();
            await settle();
            expect(handle.ready).toBe(true);
            for (const key of ['__proto__', 'constructor', 'toString']) {
                expect(Object.hasOwn(handle.fields ?? {}, key)).toBe(true);
                expect(Object.hasOwn(handle.fields?.[key]?.filters ?? {}, key)).toBe(true);
            }
            expect(Reflect.get(handle.fields ?? {}, 'hasOwnProperty')).toBeUndefined();
            expect(
                Reflect.get(handle.fields?.[name]?.filters ?? {}, 'hasOwnProperty'),
            ).toBeUndefined();
            const special = handle.fields?.[name]?.search;
            if (!special) throw new Error('Missing legal search');
            const searched = intent({
                fields: Object.fromEntries([
                    ...Object.entries(unrelated),
                    [name, { search: { value: 'Grace', matchType: 'contains' } }],
                ]),
                paging: { mode: 'offset', current: 1, per_page: 3 },
            });
            request.mockResolvedValueOnce(respond(page(searched, capabilities)));
            expect(await special.call({ value: 'Grace', matchType: 'contains' })).toEqual({
                status: 'success',
            });
            expect(sent()).toEqual({ version: 2, state: searched });
            const named = handle.fields?.[name]?.filters?.[name];
            if (!named) throw new Error('Missing legal filter');
            const filtered = intent({
                ...searched,
                fields: Object.fromEntries([
                    ...Object.entries(unrelated),
                    [
                        name,
                        {
                            search: { value: 'Grace', matchType: 'contains' },
                            filters: Object.fromEntries([[name, { value: 'active' }]]),
                        },
                    ],
                ]),
            });
            request.mockResolvedValueOnce(respond(page(filtered, capabilities)));
            await named.call({ value: 'active' });
            expect(sent()).toEqual({ version: 2, state: filtered });
            request.mockResolvedValueOnce(respond(page(searched, capabilities)));
            await named.clear();
            expect(sent()).toEqual({ version: 2, state: searched });
            const cleared = intent({ ...searched, fields: unrelated });
            request.mockResolvedValueOnce(respond(page(cleared, capabilities)));
            await special.clear();
            expect(sent()).toEqual({ version: 2, state: cleared });
            expect(Reflect.get(Object.prototype, 'search')).toBeUndefined();
            expect(Reflect.get(Object.prototype, 'filters')).toBeUndefined();
        },
    );
    it('keeps protected views null before authorization and exposes fixed global metadata only', async () => {
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const { handle } = create();
        expect(handle).toMatchObject({
            ready: false,
            fields: null,
            definition: null,
            capabilities: null,
            metadata: null,
            global: null,
        });
        pending.resolve(respond(page()));
        await settle();
        expect(sent()).toEqual({ version: 2 });
        expect(handle.accepted).toEqual({ order: { kind: 'source' }, paging: intent().paging });
        expect(handle.global).toEqual(definition.global);
        expect(handle.metadata).toEqual({ records: { mode: 'unidentified' }, channel: null });
        expect(handle.fields?.amount?.order).toBeNull();
        expect(handle.fields?.name?.filters).toBeNull();
    });
    it('gives the decoder a detached immutable definition and validates detached output', async () => {
        const fixture = page();
        request.mockResolvedValueOnce(respond(fixture));
        let decoded: AdapterDefinition | undefined;
        const { handle } = create({
            decodeDefinition(value) {
                expect(Object.isFrozen(value.fields.name?.search?.matchTypes)).toBe(true);
                expect(Reflect.set(value.fields, 'invented', definition.fields.name)).toBe(false);
                decoded = { ...value, fields: { name: definition.fields.name } };
                return decoded;
            },
        });
        await settle();
        expect(handle.ready).toBe(true);
        expect(handle.fields?.status).toBeUndefined();
        if (!decoded) throw new Error('Decoder did not run');
        Reflect.set(decoded.fields, 'status', definition.fields.status);
        expect(handle.fields?.status).toBeUndefined();
        expect(fixture.capabilities).toEqual(definition);
    });
    it.each(['field', 'match', 'filter', 'direction', 'size'])(
        'rejects decoder permission expansion in %s',
        async (kind) => {
            request.mockResolvedValueOnce(respond(page()));
            const { handle } = create({
                decodeDefinition(value) {
                    if (kind === 'field')
                        return {
                            ...value,
                            fields: { ...value.fields, private: definition.fields.name },
                        };
                    if (kind === 'match')
                        return {
                            ...value,
                            fields: {
                                ...value.fields,
                                name: {
                                    ...definition.fields.name,
                                    search: { valueType: 'string', matchTypes: ['ends_with'] },
                                },
                            },
                        };
                    if (kind === 'filter')
                        return {
                            ...value,
                            fields: {
                                ...value.fields,
                                status: {
                                    ...definition.fields.status,
                                    filters: { selected: { multiple: false, values: ['private'] } },
                                },
                            },
                        };
                    if (kind === 'direction')
                        return {
                            ...value,
                            fields: {
                                ...value.fields,
                                amount: {
                                    ...definition.fields.amount,
                                    order: definition.fields.name.order,
                                },
                            },
                        };
                    return { ...value, paging: { ...value.paging, per_page_options: [2, 3, 500] } };
                },
            });
            await settle();
            expect(handle).toMatchObject({
                ready: false,
                fields: null,
                error: { kind: 'protocol' },
            });
        },
    );
    it('detaches row decoder output from caller-owned mutable objects', async () => {
        const decoded = { id: 1, nested: { name: 'Original' }, labels: ['one'] };
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create({ decodeRow: () => decoded });
        await settle();
        decoded.nested.name = 'Changed';
        decoded.labels.push('two');
        expect(handle.data[0]).toEqual({ id: 1, nested: { name: 'Original' }, labels: ['one'] });
        expect(Object.isFrozen(handle.data[0])).toBe(true);
    });
    it('rejects decoder narrowing incompatible with accepted selections', async () => {
        request.mockResolvedValueOnce(
            respond(
                page(
                    intent({
                        fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
                    }),
                ),
            ),
        );
        const { handle } = create({ decodeDefinition: (value) => ({ ...value, fields: {} }) });
        await settle();
        expect(handle).toMatchObject({
            ready: false,
            requested: null,
            fields: null,
            error: { kind: 'protocol' },
        });
    });
    it.each([NaN, Infinity, -Infinity, Number.MAX_SAFE_INTEGER + 1])(
        'rejects nonportable numeric search %s without transport',
        async (value) => {
            request.mockResolvedValueOnce(respond(page()));
            const { handle } = create();
            await settle();
            const before = handle.requested,
                capability = handle.fields?.amount?.search;
            if (!capability) throw new Error('Missing numeric search');
            expect(await capability.call({ value, matchType: 'equals' })).toMatchObject({
                status: 'failure',
                error: { kind: 'validation' },
            });
            expect(handle.requested).toBe(before);
            expect(request).toHaveBeenCalledTimes(1);
        },
    );
    it('validates whole replacement before changing criteria or cancelling the pending read', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const read = search(handle).call({ value: 'Ada', matchType: 'contains' });
        await settle();
        const before = handle.requested;
        expect(
            await handle.replaceState(
                intent({
                    fields: {
                        name: { search: { value: 'Grace', matchType: 'contains' } },
                        private: { search: { value: 'secret', matchType: 'equals' } },
                    },
                }),
            ),
        ).toMatchObject({ status: 'failure', error: { kind: 'validation' } });
        expect(handle.requested).toBe(before);
        expect(request.mock.calls[1]?.[0].signal?.aborted).toBe(false);
        pending.resolve(
            respond(
                page(
                    intent({
                        fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
                    }),
                ),
            ),
        );
        expect(await read).toEqual({ status: 'success' });
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('keeps a later page when the active order is selected again', async () => {
        const selected = intent({
            order: {
                kind: 'fields',
                terms: [{ field: 'name', direction: 'desc', sortType: 'natural' }],
            },
            paging: { mode: 'offset', current: 2, per_page: 2 },
        });
        request.mockResolvedValueOnce(respond(page(selected)));
        const { handle } = create();
        await settle();
        const outcome = await order(handle).call({ direction: 'desc', sortType: 'natural' });
        expect(outcome).toEqual({ status: 'success' });
        expect(handle.requested).toEqual(selected);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it.each([null, undefined])(
        'rejects missing operation input %s without treating it as clear',
        async (value) => {
            const selected = intent({
                fields: {
                    name: { search: { value: 'Ada', matchType: 'contains' } },
                    status: { filters: { selected: { value: null } } },
                },
                order: {
                    kind: 'fields',
                    terms: [{ field: 'name', direction: 'asc', sortType: 'natural' }],
                },
            });
            request.mockResolvedValueOnce(respond(page(selected)));
            const { handle } = create();
            await settle();
            for (const capability of [search(handle), order(handle), filter(handle)]) {
                // @ts-expect-error JavaScript callers can pass missing operation inputs.
                const outcome = await capability.call(value);
                expect(outcome).toMatchObject({
                    status: 'failure',
                    error: { kind: 'validation' },
                });
            }
            expect(handle.requested).toEqual(selected);
            expect(request).toHaveBeenCalledTimes(1);
        },
    );
    it('keeps page position for identical criteria and inactive clears', async () => {
        const selected = intent({
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
            paging: { mode: 'offset', current: 2, per_page: 2 },
        });
        request.mockResolvedValueOnce(respond(page(selected)));
        const { handle } = create();
        await settle();
        await search(handle).call({ value: 'Ada', matchType: 'contains' });
        await filter(handle).clear();
        await order(handle).clear();
        await handle.paging.per_page.setValue(2);
        expect(handle.requested).toEqual(selected);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('invalidates every saved operation when its source changes', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle, source } = create();
        await settle();
        const saved = {
            reload: handle.paging.reload,
            next: handle.paging.goToNext,
            size: handle.paging.per_page.setValue,
            replace: handle.replaceState,
            reset: handle.resetState,
        };
        request.mockResolvedValueOnce(respond(page()));
        source.value = 'Other';
        await settle();
        expect(await saved.reload()).toEqual({ status: 'superseded' });
        expect(await saved.next()).toEqual({ status: 'superseded' });
        expect(await saved.size(3)).toEqual({ status: 'superseded' });
        expect(await saved.replace(intent())).toEqual({ status: 'superseded' });
        expect(await saved.reset()).toEqual({ status: 'superseded' });
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('combines debounced latest search with immediate order and settles superseded search', async () => {
        vi.useFakeTimers();
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create({ searchDebounce: 300 });
        await settle();
        const typed = search(handle).call({ value: 'Ada', matchType: 'contains' });
        expect(handle.requested).toMatchObject({ fields: { name: { search: { value: 'Ada' } } } });
        expect(request).toHaveBeenCalledTimes(1);
        const combined = intent({
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
            order: {
                kind: 'fields',
                terms: [{ field: 'name', direction: 'desc', sortType: 'natural' }],
            },
        });
        request.mockResolvedValueOnce(respond(page(combined)));
        const sorted = order(handle).call({ direction: 'desc', sortType: 'natural' });
        expect(await typed).toEqual({ status: 'superseded' });
        expect(await sorted).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: combined });
        await vi.advanceTimersByTimeAsync(500);
        expect(request).toHaveBeenCalledTimes(2);
    });
    it.each(['reload', 'reset'] as const)(
        'coalesces equivalent pending %s commands',
        async (command) => {
            request.mockResolvedValueOnce(respond(page()));
            const { handle } = create();
            await settle();
            const pending = deferred<HttpResponse>();
            request.mockReturnValueOnce(pending.promise);
            const run = command === 'reload' ? handle.paging.reload : handle.resetState;
            const first = run(),
                duplicate = run();
            expect(duplicate).toBe(first);
            await settle();
            expect(request).toHaveBeenCalledTimes(2);
            pending.resolve(respond(page()));
            expect(await first).toEqual({ status: 'success' });
            expect(await duplicate).toEqual({ status: 'success' });
        },
    );
    it('dispatches explicit search clear immediately despite configured debounce', async () => {
        vi.useFakeTimers();
        const selected = intent({
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(page(selected)));
        const { handle } = create({ searchDebounce: 300 });
        await settle();
        request.mockResolvedValueOnce(respond(page()));
        const cleared = search(handle).clear();
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(sent()).toEqual({ version: 2, state: intent() });
        expect(await cleared).toEqual({ status: 'success' });
        await vi.advanceTimersByTimeAsync(500);
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('coalesces equivalent pending work and ignores inactive clears', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        expect(await search(handle).clear()).toEqual({ status: 'success' });
        expect(request).toHaveBeenCalledTimes(1);
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const first = search(handle).call({ value: 'Ada', matchType: 'contains' });
        const duplicate = search(handle).call({ value: 'Ada', matchType: 'contains' });
        expect(duplicate).toBe(first);
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        const selected = intent({
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
        });
        pending.resolve(respond(page(selected)));
        await first;
        await search(handle).call({ value: 'Ada', matchType: 'contains' });
        expect(request).toHaveBeenCalledTimes(2);
    });
    it.each([null, false, 0, '0'])(
        'preserves selected scalar %s while empty search clears only search',
        async (value) => {
            const initial = intent({
                fields: {
                    name: { search: { value: 'Ada', matchType: 'contains' } },
                    status: { filters: { selected: { value } } },
                },
                paging: { mode: 'offset', current: 2, per_page: 3 },
            });
            request.mockResolvedValueOnce(respond(page(initial)));
            const { handle } = create();
            await settle();
            const expected = intent({
                fields: { status: { filters: { selected: { value } } } },
                paging: { mode: 'offset', current: 1, per_page: 3 },
            });
            request.mockResolvedValueOnce(respond(page(expected)));
            expect(filter(handle).current).toEqual({ value });
            await search(handle).call({ value: '', matchType: 'contains' });
            expect(sent()).toEqual({ version: 2, state: expected });
        },
    );
    it('clears an empty multiple filter without removing other criteria', async () => {
        const selected = intent({
            fields: {
                name: { search: { value: 'Ada', matchType: 'contains' } },
                status: { filters: { selected: { value: null }, tags: { value: ['a', 'b'] } } },
            },
        });
        request.mockResolvedValueOnce(respond(page(selected)));
        const { handle } = create();
        await settle();
        const tags = handle.fields?.status?.filters?.tags;
        if (!tags) throw new Error('Missing multiple filter');
        const expected = intent({
            fields: {
                name: selected.fields.name ?? {},
                status: { filters: { selected: { value: null } } },
            },
        });
        request.mockResolvedValueOnce(respond(page(expected)));
        await tags.call({ value: [] });
        expect(sent()).toEqual({ version: 2, state: expected });
        expect(handle.fields?.status?.filters?.tags?.current).toBeNull();
    });
    it('preserves position only when requested and resets using server defaults', async () => {
        const initial = intent({ paging: { mode: 'offset', current: 2, per_page: 3 } });
        request.mockResolvedValueOnce(respond(page(initial)));
        const { handle } = create();
        await settle();
        const expected = intent({
            ...initial,
            fields: { status: { filters: { selected: { value: false } } } },
        });
        request.mockResolvedValueOnce(respond(page(expected)));
        await filter(handle).call({ value: false }, { page: 'preserve' });
        expect(sent()).toEqual({ version: 2, state: expected });
        request.mockResolvedValueOnce(respond(page()));
        await handle.resetState();
        expect(sent()).toEqual({ version: 2 });
        expect(handle.requested).toEqual(intent());
    });
    it.each(['access', 'validation', 'unsupported_operation', 'server'])(
        'classifies real Inertia 422 %s markers',
        async (kind) => {
            request.mockResolvedValueOnce(respond(page()));
            const { handle } = create();
            await settle();
            request.mockResolvedValueOnce(
                respond(
                    {
                        version: 2,
                        error: { kind, code: 'rejected', message: 'Rejected' },
                        errors: { _dapter_kind: [kind] },
                    },
                    422,
                ),
            );
            expect(await handle.paging.reload()).toMatchObject({
                status: 'failure',
                error: { kind },
            });
            expect(handle.ready).toBe(kind !== 'access');
            expect(handle.fields === null).toBe(kind === 'access');
            expect(handle.metadata === null).toBe(kind === 'access');
        },
    );
    it('retains accepted data after network failure and recovers on reload', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        expect(await handle.paging.reload()).toMatchObject({
            status: 'failure',
            error: { kind: 'network' },
        });
        expect(handle.ready).toBe(true);
        expect(handle.definition).not.toBeNull();
        request.mockResolvedValueOnce(respond(page()));
        await handle.paging.reload();
        expect(handle.error).toBeNull();
        expect(handle.status).toBe('success');
    });
    it('revalidates saved field closures against newly restricted permissions', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        const saved = search(handle);
        request.mockResolvedValueOnce(
            respond(
                page(intent(), { ...definition, fields: { status: definition.fields.status } }),
            ),
        );
        await handle.paging.reload();
        expect(await saved.call({ value: 'Ada', matchType: 'contains' })).toMatchObject({
            status: 'failure',
            error: { kind: 'validation' },
        });
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('rejects old field closures after a source epoch change and disposal', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle, source, unmount } = create();
        await settle();
        const saved = search(handle);
        request.mockResolvedValueOnce(respond(page()));
        source.value = 'Other';
        await settle();
        expect(await saved.call({ value: 'Ada', matchType: 'contains' })).toEqual({
            status: 'superseded',
        });
        expect(request).toHaveBeenCalledTimes(2);
        const current = search(handle);
        unmount();
        expect(await current.call({ value: 'Grace', matchType: 'contains' })).toEqual({
            status: 'superseded',
        });
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('does not publish an older response after a winning newer request', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        const old = deferred<HttpResponse>();
        request.mockReturnValueOnce(old.promise);
        const obsolete = search(handle).call({ value: 'Ada', matchType: 'contains' });
        await settle();
        const selected = intent({
            fields: { name: { search: { value: 'Grace', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(page(selected, definition, 'Winner')));
        await search(handle).call({ value: 'Grace', matchType: 'contains' });
        expect(await obsolete).toEqual({ status: 'superseded' });
        old.resolve(respond(page(intent(), definition, 'Obsolete')));
        await settle();
        expect(handle.data[0]).toMatchObject({ name: 'Winner' });
        expect(handle.requested).toEqual(selected);
    });
    it('shares owner work with borrowed child views and keeps separate ids independent', async () => {
        request.mockResolvedValue(respond(page()));
        let owner: DataAdapterHandle | undefined,
            borrowed: DataAdapterHandle | undefined,
            independent: DataAdapterHandle | undefined;
        const showChild = ref(true);
        const Child = defineComponent({
            setup() {
                if (!owner) throw new Error('Missing owner');
                borrowed = useDataAdapter(owner);
                return () => h('output');
            },
        });
        cleanups.push(
            mountTree(() => {
                owner = useDataAdapter('Products', { id: 'shared', protocol: 2, url: false });
                independent = useDataAdapter('Products', {
                    id: 'independent',
                    protocol: 2,
                    url: false,
                });
                const repeated = useDataAdapter('Products', { id: 'shared' });
                expect(repeated).toBe(owner);
                return () => h('section', showChild.value ? [h(Child)] : []);
            }),
        );
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(borrowed).toBe(owner);
        if (!owner || !independent) throw new Error('Missing owners');
        showChild.value = false;
        await settle();
        request.mockResolvedValueOnce(
            respond(
                page(
                    intent({
                        fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
                    }),
                ),
            ),
        );
        await search(owner).call({ value: 'Ada', matchType: 'contains' });
        expect(owner.requested).toMatchObject({ fields: { name: { search: { value: 'Ada' } } } });
        expect(independent.requested).toEqual(intent());
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('uses latest page intent even from a previously rendered paging object', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create();
        await settle();
        const paging = handle.paging;
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const second = paging.goToNext();
        await settle();
        request.mockResolvedValueOnce(
            respond(page(intent({ paging: { mode: 'offset', current: 3, per_page: 2 } }))),
        );
        await paging.goToNext();
        expect(await second).toEqual({ status: 'superseded' });
        expect(sent()).toMatchObject({ state: { paging: { current: 3 } } });
        pending.resolve(
            respond(page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
        );
        await settle();
        expect(handle.paging.current).toBe(3);
    });
    it('settles an in-flight operation immediately on disposal and ignores its late response', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const { handle, unmount } = create();
        await settle();
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const action = handle.paging.reload();
        await settle();
        unmount();
        expect(await action).toEqual({ status: 'superseded' });
        expect(handle.fields).toBeNull();
        pending.resolve(respond(page(intent(), definition, 'Late')));
        await settle();
        expect(handle.ready).toBe(false);
        expect(handle.data).toEqual([]);
    });
    it('does not overwrite a new source generation triggered during accepted publication', async () => {
        const first = deferred<HttpResponse>(),
            second = deferred<HttpResponse>();
        request.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const { handle } = create({}, (adapter, source) => {
            let switched = false;
            watch(
                () => adapter.accepted,
                (value) => {
                    if (value && !switched) {
                        switched = true;
                        source.value = 'Other';
                    }
                },
                { flush: 'sync' },
            );
        });
        first.resolve(
            respond(page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
        );
        await settle();
        expect(handle).toMatchObject({
            ready: false,
            requested: null,
            accepted: null,
            status: 'loading',
        });
        expect(request.mock.calls[1]?.[0].url).toContain('/Other');
        second.resolve(respond(page()));
        await settle();
        expect(handle.ready).toBe(true);
    });
    it('does not overwrite a new action triggered during accepted publication', async () => {
        const initial = deferred<HttpResponse>(),
            next = deferred<HttpResponse>();
        request.mockReturnValueOnce(initial.promise).mockReturnValueOnce(next.promise);
        let action: Promise<unknown> | undefined;
        const { handle } = create({}, (adapter) => {
            let changed = false;
            watch(
                () => adapter.accepted,
                (value) => {
                    if (value && !changed) {
                        changed = true;
                        action = search(adapter).call({ value: 'Grace', matchType: 'contains' });
                    }
                },
                { flush: 'sync' },
            );
        });
        initial.resolve(respond(page()));
        await settle();
        expect(handle.status).toBe('loading');
        expect(handle.requested).toMatchObject({
            fields: { name: { search: { value: 'Grace' } } },
        });
        const selected = intent({
            fields: { name: { search: { value: 'Grace', matchType: 'contains' } } },
        });
        next.resolve(respond(page(selected)));
        await action;
        expect(handle.requested).toEqual(selected);
    });
});
