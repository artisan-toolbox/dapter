import { http, HttpNetworkError, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { createRenderer, defineComponent, h, nextTick, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type DataAdapterHandle,
    type DataAdapterProps,
    type DataAdapterSlot,
} from '@artisan-toolbox/dapter';

import corpus from '../../resources/contracts/read-v1.json';

type Host = { parent: Host | null; children: Host[]; text: string };
const node = (): Host => ({ parent: null, children: [], text: '' });
const renderer = createRenderer<Host, Host>({
    createElement: node,
    createText: node,
    createComment: node,
    insert(child, parent, anchor) {
        if (child.parent)
            child.parent.children = child.parent.children.filter((item) => item !== child);
        child.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        if (index < 0) parent.children.push(child);
        else parent.children.splice(index, 0, child);
    },
    remove(child) {
        if (child.parent)
            child.parent.children = child.parent.children.filter((item) => item !== child);
    },
    setText(target, text) {
        target.text = text;
    },
    setElementText(target, text) {
        target.text = text;
    },
    parentNode: (target) => target.parent,
    nextSibling: (target) =>
        target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    patchProp() {},
});
const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
function deferred() {
    let resolve: (response: HttpResponse) => void = () => {
        throw new Error('Uninitialized deferred');
    };
    const promise = new Promise<HttpResponse>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
function page(current = 1, perPage = 2, total = 5) {
    return {
        version: 1,
        data: Array.from(
            { length: Math.min(perPage, Math.max(0, total - (current - 1) * perPage)) },
            (_, index) => ({ id: (current - 1) * perPage + index + 1, nested: { title: 'Item' } }),
        ),
        requested: {
            version: 1,
            fields: {},
            global: {},
            searchMatch: 'all',
            order: { kind: 'default' },
            paging: { mode: 'offset', current, per_page: perPage },
        },
        state: {
            order: { kind: 'source' },
            paging: { mode: 'offset', current, per_page: perPage },
        },
        paging: {
            mode: 'offset',
            current,
            pages: Math.max(1, Math.ceil(total / perPage)),
            per_page: perPage,
            total,
            filtered: total,
            has_previous: current > 1,
            has_next: current < Math.max(1, Math.ceil(total / perPage)),
        },
        capabilities: {
            fields: {},
            global: {
                multi_order: false,
                multi_order_limit: 1,
                searchMatch: { default: 'all', allowed: ['all'] },
                filters: null,
                search: null,
                order: null,
            },
            paging: { mode: 'offset', per_page: perPage, per_page_options: [2, 3] },
        },
    };
}
async function settle() {
    for (let count = 0; count < 8; count++) await Promise.resolve();
    await nextTick();
}
type SourceProps = Extract<DataAdapterProps, { source: string; paging?: 'offset' | undefined }>;
function mount(initial: SourceProps = { source: 'Products' }) {
    const props = reactive(initial);
    const states: DataAdapterSlot[] = [];
    let latest: DataAdapterSlot | undefined;
    const app = renderer.createApp(
        defineComponent({
            setup: () => () =>
                h(DataAdapter<SourceProps>, props, {
                    default: (state: DataAdapterSlot) => {
                        latest = state;
                        states.push({ ...state });
                        return h('output');
                    },
                }),
        }),
    );
    app.mount(node());
    const cleanup = () => app.unmount();
    cleanups.push(cleanup);
    return {
        props,
        states,
        get slot() {
            if (!latest) throw new Error('Missing public slot');
            return latest;
        },
        unmount: () => {
            app.unmount();
            cleanups.splice(cleanups.indexOf(cleanup), 1);
        },
    };
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
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
});

describe('public DataAdapter', () => {
    it.each([401, 403, 404])(
        'clears rows conservatively for unknown access status %i',
        async (status) => {
            request.mockResolvedValueOnce(respond(page()));
            const adapter = mount();
            await settle();
            request.mockResolvedValueOnce(respond({ message: 'Unavailable' }, status));
            const outcome = await adapter.slot.paging.reload();
            await settle();
            expect(outcome).toMatchObject({ status: 'failure', error: { kind: 'access' } });
            expect(adapter.slot).toMatchObject({
                ready: false,
                data: [],
                accepted: null,
                error: { kind: 'access' },
            });
        },
    );
    it.each(corpus.invalid_failures)(
        'clears accepted corpus rows after malformed failure $id',
        async ({ response, status }) => {
            const initial = corpus.successes[0];
            if (!initial) throw new Error('Missing initial protocol example');
            request.mockResolvedValueOnce(respond(initial.response));
            const adapter = mount();
            await settle();
            expect(adapter.slot.data).toEqual(initial.response.data);
            request.mockResolvedValueOnce(respond(response, status));
            const outcome = await adapter.slot.paging.reload();
            await settle();
            expect(outcome).toMatchObject({ status: 'failure', error: { kind: 'protocol' } });
            expect(adapter.slot).toMatchObject({
                ready: false,
                data: [],
                accepted: null,
                error: { kind: 'protocol' },
            });
        },
    );
    it.each(corpus.successes)('mounts successful corpus example $id', async ({ response }) => {
        request.mockResolvedValueOnce(respond(response));
        const adapter = mount();
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            data: response.data,
            requested: response.requested,
            accepted: response.state,
            error: null,
        });
    });
    it.each(corpus.failures)(
        'classifies corpus failure $id through real Inertia transport',
        async ({ response, status }) => {
            const initial = corpus.successes[0];
            if (!initial) throw new Error('Missing initial protocol example');
            request.mockResolvedValueOnce(respond(initial.response));
            const adapter = mount();
            await settle();
            request.mockResolvedValueOnce(respond(response, status));
            const outcome = await adapter.slot.paging.reload();
            await settle();
            expect(outcome).toMatchObject({
                status: 'failure',
                error: { kind: response.error.kind },
            });
            expect(adapter.slot.error?.kind).toBe(response.error.kind);
            expect(adapter.slot.ready).toBe(response.error.kind !== 'access');
            expect(adapter.slot.data).toEqual(
                response.error.kind === 'access' ? [] : initial.response.data,
            );
        },
    );

    it('starts unready, mounts asynchronously, and uses real useHttp JSON transport through the package route', async () => {
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        const adapter = mount();
        expect(adapter.states[0]).toMatchObject({
            ready: false,
            status: 'idle',
            data: [],
            requested: null,
            accepted: null,
            paging: { empty: false, current: null, total: null },
        });
        expect(request).not.toHaveBeenCalled();
        await nextTick();
        expect(adapter.slot.paging.loading).toBe(true);
        expect(request).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0]?.[0]).toMatchObject({
            method: 'post',
            url: 'https://example.test/dapter/data/Products',
            data: '{"version":1}',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            signal: expect.any(AbortSignal),
        });
        pending.resolve(respond(page()));
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            status: 'success',
            error: null,
            data: [{ id: 1 }, { id: 2 }],
            paging: {
                loading: false,
                current: 1,
                pages: 3,
                total: 5,
                filtered: 5,
                has_next: true,
                has_previous: false,
                per_page: { value: 2, options: [2, 3] },
            },
        });
        expect(Object.isFrozen(adapter.slot.data)).toBe(true);
        expect(Object.isFrozen(adapter.slot.data[0])).toBe(true);
        expect(Object.isFrozen(adapter.slot.requested)).toBe(true);
        expect(Object.isFrozen(adapter.slot.paging)).toBe(true);
    });
    it('pages, coalesces identical requests, skips inactive actions, resets size to page one, and always reloads', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        await adapter.slot.paging.goToFirst();
        await adapter.slot.paging.goToPrevious();
        expect(request).toHaveBeenCalledTimes(1);
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        const next = adapter.slot.paging.goToNext();
        const same = adapter.slot.paging.goToPage(2);
        expect(next).toBe(same);
        await Promise.resolve();
        expect(request).toHaveBeenCalledTimes(2);
        expect(JSON.parse(String(request.mock.calls[1]?.[0].data))).toMatchObject({
            version: 1,
            state: { paging: { current: 2, per_page: 2 } },
        });
        pending.resolve(respond(page(2)));
        expect(await next).toEqual({ status: 'success' });
        await settle();
        request.mockResolvedValueOnce(respond(page(3)));
        await adapter.slot.paging.goToLast();
        await settle();
        await adapter.slot.paging.goToNext();
        expect(request).toHaveBeenCalledTimes(3);
        request.mockResolvedValueOnce(respond(page(2)));
        await adapter.slot.paging.goToPrevious();
        await settle();
        request.mockResolvedValueOnce(respond(page(1, 3)));
        await adapter.slot.paging.per_page.setValue(3);
        await settle();
        expect(JSON.parse(String(request.mock.calls[4]?.[0].data)).state.paging).toEqual({
            mode: 'offset',
            current: 1,
            per_page: 3,
        });
        expect((await adapter.slot.paging.per_page.setValue(99)).status).toBe('failure');
        expect((await adapter.slot.paging.goToPage(1.5)).status).toBe('failure');
        request.mockResolvedValueOnce(respond(page(1, 3)));
        await adapter.slot.paging.reload();
        await settle();
        expect(request).toHaveBeenCalledTimes(6);
    });
    it('retries the retained requested size after a network failure', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        expect((await adapter.slot.paging.per_page.setValue(3)).status).toBe('failure');
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            requested: { paging: { current: 1, per_page: 3 } },
            paging: { per_page: { value: 2 } },
        });
        request.mockResolvedValueOnce(respond(page(1, 3)));
        expect(await adapter.slot.paging.per_page.setValue(3)).toEqual({ status: 'success' });
        await settle();
        expect(request).toHaveBeenCalledTimes(3);
        expect(JSON.parse(String(request.mock.calls[2]?.[0].data)).state.paging).toEqual({
            mode: 'offset',
            current: 1,
            per_page: 3,
        });
        expect(adapter.slot.paging.per_page.value).toBe(3);
        expect(adapter.slot.error).toBeNull();
    });
    it('retries the retained page when selecting its already requested size', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        await adapter.slot.paging.goToPage(3);
        await settle();
        request.mockResolvedValueOnce(respond(page(3)));
        expect(await adapter.slot.paging.per_page.setValue(2)).toEqual({ status: 'success' });
        await settle();
        expect(JSON.parse(String(request.mock.calls[2]?.[0].data)).state.paging).toEqual({
            mode: 'offset',
            current: 3,
            per_page: 2,
        });
        expect(adapter.slot.paging.current).toBe(3);
    });
    it('preserves a newer request started by a decoder before committing', async () => {
        const refreshed = deferred();
        request.mockResolvedValueOnce(respond(page())).mockReturnValueOnce(refreshed.promise);
        let adapter: ReturnType<typeof mount> | undefined;
        let triggered = false;
        adapter = mount({
            source: 'Products',
            decodeRow: (value) => {
                if (adapter && !triggered) {
                    triggered = true;
                    void adapter.slot.paging.reload();
                }
                return value;
            },
        });
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(adapter.slot).toMatchObject({ ready: false, status: 'loading', error: null });
        refreshed.resolve(respond(page(2)));
        await settle();
        expect(adapter.slot.paging.current).toBe(2);
    });
    it('does not commit when a decoder unmounts its adapter', async () => {
        request.mockResolvedValueOnce(respond(page()));
        let adapter: ReturnType<typeof mount> | undefined;
        let shouldUnmount = false;
        adapter = mount({
            source: 'Products',
            decodeRow: (value) => {
                if (adapter && shouldUnmount) {
                    shouldUnmount = false;
                    adapter.unmount();
                }
                return value;
            },
        });
        await settle();
        shouldUnmount = true;
        request.mockResolvedValueOnce(respond(page(2)));
        expect(await adapter.slot.paging.reload()).toEqual({ status: 'superseded' });
    });
    it('preserves decoded Date values and custom instance methods', async () => {
        class Label {
            #value = 'Product';
            text() {
                return this.#value;
            }
        }
        const createdAt = new Date('2026-01-01T00:00:00Z');
        const label = new Label();
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount({ source: 'Products', decodeRow: () => ({ createdAt, label }) });
        await settle();
        expect(adapter.slot).toMatchObject({ ready: true, error: null });
        const row = adapter.slot.data[0];
        if (typeof row !== 'object' || row === null || !('createdAt' in row) || !('label' in row))
            throw new Error('Missing decoded values');
        if (!(row.createdAt instanceof Date) || !(row.label instanceof Label))
            throw new Error('Missing decoded prototypes');
        expect(row.createdAt.getTime()).toBe(Date.parse('2026-01-01T00:00:00Z'));
        expect(row.label.text()).toBe('Product');
        expect(row.createdAt).toBe(createdAt);
        expect(row.label).toBe(label);
        expect(Object.isFrozen(row)).toBe(true);
        expect(Object.isFrozen(createdAt)).toBe(false);
        expect(Object.isFrozen(label)).toBe(false);
    });
    it('rejects malformed wire rows before invoking an application decoder', async () => {
        const malformed = page();
        malformed.data[0]!.id = Number.MAX_SAFE_INTEGER + 1;
        const decodeRow = vi.fn(() => ({ createdAt: new Date() }));
        request.mockResolvedValueOnce(respond(malformed));
        const adapter = mount({ source: 'Products', decodeRow });
        await settle();
        expect(decodeRow).not.toHaveBeenCalled();
        expect(adapter.slot).toMatchObject({ ready: false, error: { kind: 'protocol' } });
    });
    it('keeps configured default metadata independent from the selected page size', async () => {
        const initial = page(1, 10, 23);
        initial.capabilities.paging.per_page_options = [5, 10];
        request.mockResolvedValueOnce(respond(initial));
        const adapter = mount();
        await settle();
        const selected = page(1, 5, 23);
        selected.capabilities.paging.per_page = 10;
        selected.capabilities.paging.per_page_options = [5, 10];
        request.mockResolvedValueOnce(respond(selected));
        await adapter.slot.paging.per_page.setValue(5);
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            error: null,
            requested: { paging: { per_page: 5 } },
            accepted: { paging: { per_page: 5 } },
            paging: { per_page: { value: 5, options: [5, 10] }, pages: 5 },
        });
        expect(adapter.slot.data).toHaveLength(5);
    });
    it('adopts server clamping and empty result counts', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockResolvedValueOnce(respond(page(1, 2, 0)));
        await adapter.slot.paging.goToLast();
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            data: [],
            requested: { paging: { current: 1 } },
            accepted: { paging: { current: 1 } },
            paging: { empty: true, total: 0, pages: 1, current: 1 },
        });
    });
    it('decodes each row and fails atomically when a decoder rejects one', async () => {
        const decodeRow = vi.fn((value: unknown) => {
            if (typeof value !== 'object' || value === null || !('id' in value) || value.id === 2)
                throw new Error('Sensitive decoder details');
            return { label: `Record ${String(value.id)}` };
        });
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount({ source: 'Products', decodeRow });
        await settle();
        expect(decodeRow).toHaveBeenCalledTimes(2);
        expect(adapter.slot).toMatchObject({
            ready: false,
            data: [],
            error: { kind: 'protocol', code: 'invalid_response' },
        });
        expect(adapter.slot.error?.message).not.toContain('Sensitive');
        request.mockResolvedValueOnce(respond(page(1, 2, 1)));
        await adapter.slot.paging.reload();
        await settle();
        expect(adapter.slot.data).toEqual([{ label: 'Record 1' }]);
    });
    it.each(['validation', 'unsupported_operation', 'server'])(
        'retains accepted data after a recognized %s 422 and retries the requested page',
        async (kind) => {
            request.mockResolvedValueOnce(respond(page()));
            const adapter = mount();
            await settle();
            request.mockResolvedValueOnce(
                respond(
                    {
                        version: 1,
                        errors: { _dapter_kind: [kind] },
                        error: { kind, code: 'rejected', message: 'Rejected' },
                    },
                    422,
                ),
            );
            const outcome = await adapter.slot.paging.goToNext();
            await settle();
            expect(outcome.status).toBe('failure');
            expect(adapter.slot.ready).toBe(true);
            expect(adapter.slot.error?.kind).toBe(kind);
            expect(adapter.slot.paging.current).toBe(1);
            request.mockResolvedValueOnce(respond(page(2)));
            await adapter.slot.paging.reload();
            await settle();
            expect(JSON.parse(String(request.mock.calls[2]?.[0].data)).state.paging.current).toBe(
                2,
            );
            expect(adapter.slot.paging.current).toBe(2);
        },
    );
    it.each([401, 403, 404, 422])('clears protected rows on access failure %i', async (status) => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockResolvedValueOnce(
            respond(
                {
                    version: 1,
                    error: { kind: 'access', code: 'denied', message: 'Denied' },
                    errors: { _dapter_kind: ['access'] },
                },
                status,
            ),
        );
        await adapter.slot.paging.reload();
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: false,
            data: [],
            accepted: null,
            error: { kind: 'access' },
            paging: { current: null, total: null },
        });
    });
    it.each(['unknown422', 'null422', 'html', 'malformed', 'futureCapability', 'unsafeNumber'])(
        'fails closed on %s responses',
        async (kind) => {
            request.mockResolvedValueOnce(respond(page()));
            const adapter = mount();
            await settle();
            const malformed = page();
            if (kind === 'futureCapability') malformed.capabilities.fields = { name: {} };
            if (kind === 'unsafeNumber') malformed.paging.total = Number.MAX_SAFE_INTEGER + 1;
            request.mockResolvedValueOnce(
                kind === 'unknown422'
                    ? respond({ errors: { _dapter_kind: ['future'] } }, 422)
                    : kind === 'null422'
                      ? respond(null, 422)
                      : kind === 'html'
                        ? { status: 503, data: '<html>Maintenance</html>', headers: {} }
                        : respond(kind === 'malformed' ? { data: [] } : malformed),
            );
            await adapter.slot.paging.reload();
            await settle();
            expect(adapter.slot).toMatchObject({
                ready: false,
                data: [],
                error: { kind: 'protocol' },
                paging: { total: null },
            });
        },
    );
    it('fails closed when a transport callback throws an unexpected TypeError', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockRejectedValueOnce(new TypeError('Invalid response handling'));
        expect((await adapter.slot.paging.reload()).status).toBe('failure');
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: false,
            data: [],
            accepted: null,
            error: { kind: 'protocol' },
        });
    });
    it('retains an authorized result after network errors and sanitized server failures', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Private network details'));
        await adapter.slot.paging.reload();
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            error: { kind: 'network', code: 'request_failed' },
        });
        expect(adapter.slot.error?.message).not.toContain('Private');
        request.mockResolvedValueOnce(
            respond(
                {
                    version: 1,
                    error: { kind: 'server', code: 'unavailable', message: 'Unavailable' },
                },
                500,
            ),
        );
        await adapter.slot.paging.reload();
        await settle();
        expect(adapter.slot).toMatchObject({
            ready: true,
            error: { kind: 'server', code: 'unavailable' },
        });
    });
    it.each([200, 422, 403])(
        'ignores stale %i winners while newer work is pending and after acceptance',
        async (status) => {
            request.mockResolvedValueOnce(respond(page()));
            const adapter = mount();
            await settle();
            const old = deferred(),
                newest = deferred();
            request.mockReturnValueOnce(old.promise).mockReturnValueOnce(newest.promise);
            const oldResult = adapter.slot.paging.goToNext();
            await Promise.resolve();
            const latestResult = adapter.slot.paging.goToLast();
            await Promise.resolve();
            expect(request.mock.calls[1]?.[0].signal?.aborted).toBe(true);
            old.resolve(
                respond(
                    status === 200
                        ? page(2)
                        : {
                              errors: { _dapter_kind: ['access'] },
                              version: 1,
                              error: { kind: 'access', code: 'denied', message: 'Denied' },
                          },
                    status,
                ),
            );
            expect(await oldResult).toEqual({ status: 'superseded' });
            await settle();
            expect(adapter.slot.paging.loading).toBe(true);
            expect(adapter.slot.error).toBeNull();
            newest.resolve(respond(page(3)));
            expect(await latestResult).toEqual({ status: 'success' });
            await settle();
            expect(adapter.slot.paging.current).toBe(3);
        },
    );
    it.each([200, 422, 403])(
        'ignores stale %i responses after a newer result commits',
        async (status) => {
            request.mockResolvedValueOnce(respond(page()));
            const adapter = mount();
            await settle();
            const older = deferred(),
                newer = deferred();
            request.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
            const previous = adapter.slot.paging.goToNext();
            await Promise.resolve();
            const current = adapter.slot.paging.goToLast();
            await Promise.resolve();
            newer.resolve(respond(page(3)));
            await current;
            await settle();
            older.resolve(
                respond(
                    status === 200
                        ? { malformed: true }
                        : {
                              version: 1,
                              error: { kind: 'access', code: 'denied', message: 'Denied' },
                              errors: { _dapter_kind: ['access'] },
                          },
                    status,
                ),
            );
            expect(await previous).toEqual({ status: 'superseded' });
            await settle();
            expect(adapter.slot).toMatchObject({
                ready: true,
                error: null,
                status: 'success',
                paging: { current: 3, loading: false },
            });
        },
    );
    it('keeps retained paging methods bound to the latest accepted and requested state', async () => {
        const first = deferred();
        request.mockReturnValueOnce(first.promise);
        const adapter = mount();
        const initialPaging = adapter.slot.paging;
        first.resolve(respond(page()));
        await settle();
        request.mockResolvedValueOnce(respond(page(3)));
        await initialPaging.goToLast();
        await settle();
        expect(adapter.slot.paging.current).toBe(3);
        request.mockResolvedValueOnce(respond(page(2)));
        await initialPaging.goToPrevious();
        await settle();
        expect(adapter.slot.paging.current).toBe(2);
    });
    it('returns to an accepted page after a different request failed', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        await adapter.slot.paging.goToNext();
        await settle();
        expect(adapter.slot.requested?.paging.current).toBe(2);
        request.mockResolvedValueOnce(respond(page()));
        await adapter.slot.paging.goToFirst();
        await settle();
        expect(request).toHaveBeenCalledTimes(3);
        expect(adapter.slot.requested?.paging.current).toBe(1);
        expect(adapter.slot.error).toBeNull();
    });
    it('copies decoder output before freezing accepted rows', async () => {
        const shared = { name: 'Shared', nested: { count: 1 } };
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount({ source: 'Products', decodeRow: () => shared });
        await settle();
        shared.nested.count = 9;
        expect(adapter.slot.data).toEqual([
            { name: 'Shared', nested: { count: 1 } },
            { name: 'Shared', nested: { count: 1 } },
        ]);
        expect(Object.isFrozen(shared)).toBe(false);
    });
    it('resets source and decoder contexts and cancels on unmount', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const adapter = mount();
        await settle();
        const older = deferred(),
            changed = deferred();
        request.mockReturnValueOnce(older.promise).mockReturnValueOnce(changed.promise);
        const result = adapter.slot.paging.reload();
        await Promise.resolve();
        adapter.props.source = 'Catalog.Products';
        await nextTick();
        expect(adapter.slot.ready).toBe(false);
        expect(request.mock.calls[1]?.[0].signal?.aborted).toBe(true);
        expect(request.mock.calls[2]?.[0].url).toContain('Catalog.Products');
        older.resolve(respond(page()));
        expect(await result).toEqual({ status: 'superseded' });
        changed.resolve(respond(page()));
        await settle();
        request.mockResolvedValueOnce(respond(page()));
        adapter.props.decodeRow = () => ({ title: 'Decoded' });
        await settle();
        expect(adapter.slot.data).toEqual([{ title: 'Decoded' }, { title: 'Decoded' }]);
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        const final = adapter.slot.paging.reload();
        await Promise.resolve();
        adapter.unmount();
        expect(request.mock.calls[4]?.[0].signal?.aborted).toBe(true);
        pending.resolve(respond(page()));
        expect(await final).toEqual({ status: 'superseded' });
    });
    it('keeps same-source instances independent', async () => {
        const first = deferred(),
            second = deferred();
        request.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const one = mount(),
            two = mount();
        first.resolve(respond(page()));
        await settle();
        expect(one.slot.ready).toBe(true);
        expect(two.slot.ready).toBe(false);
        one.unmount();
        expect(request.mock.calls[1]?.[0].signal?.aborted).toBe(false);
        second.resolve(respond(page(2)));
        await settle();
        expect(two.slot.paging.current).toBe(2);
    });
});

describe('shared public adapters', () => {
    function host(setup: () => () => ReturnType<typeof h>) {
        const app = renderer.createApp(defineComponent({ setup }));
        app.mount(node());
        cleanups.push(() => app.unmount());
        return app;
    }
    function view(
        handle: DataAdapterHandle,
        inspect: (slot: DataAdapterHandle) => void = () => {},
    ) {
        return h(
            DataAdapter<{ adapter: DataAdapterHandle }>,
            { adapter: handle },
            {
                default: (slot: DataAdapterHandle) => {
                    inspect(slot);
                    return h('output');
                },
            },
        );
    }
    it('shares one stable reactive object and one mount request in composable-first order', async () => {
        const pending = deferred();
        request.mockReturnValue(pending.promise);
        let handle: DataAdapterHandle | undefined;
        let slot: DataAdapterHandle | undefined;
        host(() => {
            handle = useDataAdapter('Products');
            expect(useDataAdapter('Products')).toBe(handle);
            expect(useDataAdapter(handle)).toBe(handle);
            const adapter = handle;
            return () =>
                view(adapter, (value) => {
                    slot = value;
                });
        });
        await settle();
        expect(request).toHaveBeenCalledTimes(1);
        expect(slot).toBe(handle);
        expect(handle?.status).toBe('loading');
        pending.resolve(respond(page()));
        await settle();
        expect(slot).toBe(handle);
        expect(handle?.ready).toBe(true);
        expect(handle?.data).toHaveLength(2);
        expect(Object.keys(handle ?? {})).toEqual([
            'status',
            'error',
            'integrationError',
            'infinite',
            'share',
            'resetContext',
            'restartPolling',
            'requested',
            'accepted',
            'paging',
            'definition',
            'capabilities',
            'metadata',
            'fields',
            'global',
            'setSearchMatch',
            'actions',
            'replaceState',
            'resetState',
            'ready',
            'data',
        ]);
    });
    it('acquires a component-first owner in descendants and keeps nested source owners independent', async () => {
        request.mockResolvedValue(respond(page()));
        const acquired: DataAdapterHandle[] = [];
        const child = defineComponent({
            setup() {
                acquired.push(useDataAdapter('Products'));
                return () => h('output');
            },
        });
        host(
            () => () =>
                h(
                    DataAdapter<{ source: string }>,
                    { source: 'Products' },
                    {
                        default: (slot: DataAdapterHandle) => {
                            if (!acquired.includes(slot)) acquired.push(slot);
                            return [
                                h(child),
                                h(child),
                                h(
                                    DataAdapter<{ source: string }>,
                                    { source: 'Products' },
                                    {
                                        default: () => h(child),
                                    },
                                ),
                            ];
                        },
                    },
                ),
        );
        await settle();
        expect(acquired[1]).toBe(acquired[0]);
        expect(acquired[2]).toBe(acquired[0]);
        expect(acquired[3]).not.toBe(acquired[0]);
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('isolates explicit ids and a source-owning view from a host handle', async () => {
        request.mockResolvedValue(respond(page()));
        host(() => {
            const review = useDataAdapter('Products', { id: 'review' });
            const active = useDataAdapter('Products', { id: 'active' });
            expect(review).not.toBe(active);
            expect(useDataAdapter('Products', { id: 'review' })).toBe(review);
            return () =>
                h('main', [
                    view(review),
                    view(active),
                    h(
                        DataAdapter<{ source: string }>,
                        { source: 'Products' },
                        {
                            default: (slot: DataAdapterHandle) => {
                                expect(slot).not.toBe(review);
                                return h('output');
                            },
                        },
                    ),
                ]);
        });
        await settle();
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('does not cancel an owner request when a borrowed view unmounts or remounts', async () => {
        const pending = deferred();
        request.mockReturnValue(pending.promise);
        const visible = ref(true);
        let signal: AbortSignal | undefined;
        request.mockImplementation((config) => {
            signal = config.signal;
            return pending.promise;
        });
        host(() => {
            const adapter = useDataAdapter('Products');
            return () => h('main', visible.value ? [view(adapter)] : []);
        });
        await settle();
        visible.value = false;
        await settle();
        expect(signal?.aborted).toBe(false);
        visible.value = true;
        await settle();
        expect(request).toHaveBeenCalledTimes(1);
        pending.resolve(respond(page()));
        await settle();
    });
    it('disposes the owner, clears escaped rows, and prevents a stale response from reviving it', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        const source = ref('Products');
        let adapter: DataAdapterHandle | undefined;
        const app = host(() => {
            adapter = useDataAdapter(source, { id: 'catalog' });
            const current = adapter;
            return () => view(current);
        });
        await settle();
        expect(adapter?.ready).toBe(true);
        const result = adapter?.paging.reload();
        await settle();
        const signal = request.mock.calls[1]?.[0].signal;
        const cancelled = vi.fn();
        signal?.addEventListener('abort', cancelled);
        app.unmount();
        cleanups.pop();
        expect(signal?.aborted).toBe(true);
        expect(adapter?.ready).toBe(false);
        source.value = 'Other';
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(cancelled).toHaveBeenCalledTimes(1);
        pending.resolve(respond(page(2)));
        await settle();
        await expect(result).resolves.toEqual({ status: 'superseded' });
        await expect(adapter?.paging.reload()).resolves.toEqual({ status: 'superseded' });
        expect(adapter?.data).toEqual([]);
    });
    it('keeps owner scopes independent across repeated mounts and Vue apps', async () => {
        request.mockResolvedValue(respond(page()));
        const handles: DataAdapterHandle[] = [];
        const setup = () => {
            const adapter = useDataAdapter('Products');
            handles.push(adapter);
            return () => view(adapter);
        };
        const first = host(setup);
        host(setup);
        await settle();
        expect(handles[0]).not.toBe(handles[1]);
        first.unmount();
        cleanups.shift();
        host(setup);
        await settle();
        expect(handles[2]).not.toBe(handles[0]);
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('rejects conflicting decoders and source inputs before changing an owner', async () => {
        request.mockResolvedValue(respond(page()));
        const decode = (value: unknown) => value;
        host(() => {
            const adapter = useDataAdapter('Products', { id: 'products', decodeRow: decode });
            expect(useDataAdapter('Products', { id: 'products' })).toBe(adapter);
            expect(() =>
                useDataAdapter('Products', { id: 'products', decodeRow: (value) => value }),
            ).toThrow('conflicts');
            expect(() => useDataAdapter('Other', { id: 'products' })).toThrow('conflicts');
            const undecoded = useDataAdapter('Undecoded');
            expect(() => useDataAdapter('Undecoded', { decodeRow: decode })).toThrow('conflicts');
            return () => h('main', [view(adapter), view(undecoded)]);
        });
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('retains identity for a reactive source and synchronously clears old context', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        const source = ref('Products');
        let adapter: DataAdapterHandle | undefined;
        host(() => {
            adapter = useDataAdapter(source, { id: 'catalog' });
            expect(useDataAdapter(source, { id: 'catalog' })).toBe(adapter);
            const current = adapter;
            return () => view(current);
        });
        await settle();
        const original = adapter;
        expect(adapter?.ready).toBe(true);
        source.value = 'Other';
        expect(adapter?.ready).toBe(false);
        expect(adapter?.data).toEqual([]);
        await settle();
        expect(request.mock.calls[1]?.[0].url).toContain('/Other');
        pending.resolve(respond(page()));
        await settle();
        expect(adapter).toBe(original);
        expect(adapter?.ready).toBe(true);
    });
    it('detaches a replaced bound view without cancelling either live owner', async () => {
        const first = deferred();
        const second = deferred();
        request.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const selected = ref(false);
        const errors: unknown[] = [];
        let renders = 0;
        let source: DataAdapterHandle | undefined;
        const app = renderer.createApp(
            defineComponent({
                setup() {
                    const one = useDataAdapter('Products', { id: 'one' });
                    const two = useDataAdapter('Products', { id: 'two' });
                    source = one;
                    return () =>
                        view(selected.value ? two : one, () => {
                            renders++;
                        });
                },
            }),
        );
        app.config.errorHandler = (error) => {
            errors.push(error);
        };
        app.mount(node());
        cleanups.push(() => app.unmount());
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        const before = renders;
        selected.value = true;
        await settle();
        expect(errors).toHaveLength(1);
        expect(String(errors[0])).toContain('cannot change');
        expect(renders).toBe(before);
        expect(request.mock.calls.every(([config]) => !config.signal?.aborted)).toBe(true);
        first.resolve(respond(page()));
        second.resolve(respond(page()));
        await settle();
        expect(source?.ready).toBe(true);
        expect(renders).toBe(before);
    });
    it('rejects different reactive selectors and same-id source conflicts at component boundaries', async () => {
        request.mockResolvedValue(respond(page()));
        let acquired: DataAdapterHandle | undefined;
        const child = defineComponent({
            setup() {
                acquired = useDataAdapter('Products');
                expect(() => useDataAdapter(ref('Products'), { id: 'catalog' })).toThrow(
                    'conflicts',
                );
                expect(() => useDataAdapter('Other', { id: 'catalog' })).toThrow('conflicts');
                return () => h('output');
            },
        });
        host(
            () => () =>
                h(
                    DataAdapter<{ source: string; id: string }>,
                    { source: 'Products', id: 'catalog' },
                    {
                        default: (slot: DataAdapterHandle) => [
                            h(child),
                            h('output', String(slot === acquired)),
                        ],
                    },
                ),
        );
        await settle();
        expect(acquired?.ready).toBe(true);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('lets descendants borrow a reactive bound owner by source while sharing one request generation', async () => {
        request.mockResolvedValueOnce(respond(page()));
        const pending = deferred();
        request.mockReturnValueOnce(pending.promise);
        let owner: DataAdapterHandle | undefined;
        let childHandle: DataAdapterHandle | undefined;
        const child = defineComponent({
            setup() {
                childHandle = useDataAdapter('Products');
                return () => h('output');
            },
        });
        host(() => {
            owner = useDataAdapter(ref('Products'), { id: 'catalog' });
            const current = owner;
            return () =>
                h(
                    DataAdapter<{ adapter: DataAdapterHandle }>,
                    { adapter: current },
                    {
                        default: () => h(child),
                    },
                );
        });
        await settle();
        expect(childHandle).toBe(owner);
        const first = owner?.paging.goToPage(2);
        const joined = childHandle?.paging.goToPage(2);
        expect(first).toBe(joined);
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        pending.resolve(respond(page(2)));
        await expect(first).resolves.toEqual({ status: 'success' });
        expect(owner?.paging.current).toBe(2);
    });
    it('rejects stale access denials after a shared source changes', async () => {
        const old = deferred();
        request.mockReturnValueOnce(old.promise).mockResolvedValueOnce(respond(page()));
        const source = ref('Products');
        let adapter: DataAdapterHandle | undefined;
        host(() => {
            adapter = useDataAdapter(source, { id: 'catalog' });
            const current = adapter;
            return () => view(current);
        });
        await settle();
        source.value = 'Other';
        await settle();
        expect(adapter?.ready).toBe(true);
        old.resolve(respond({ message: 'Forbidden' }, 403));
        await settle();
        expect(adapter?.ready).toBe(true);
        expect(adapter?.error).toBeNull();
        expect(adapter?.data).toHaveLength(2);
    });
    it('disposes a source owner and rejects switching it into a bound view without cancelling the supplied owner', async () => {
        const owned = deferred();
        const supplied = deferred();
        request.mockReturnValueOnce(owned.promise).mockReturnValueOnce(supplied.promise);
        const selected = ref(false);
        const errors: unknown[] = [];
        let suppliedHandle: DataAdapterHandle | undefined;
        let renders = 0;
        const app = renderer.createApp(
            defineComponent({
                setup() {
                    suppliedHandle = useDataAdapter('Other');
                    const external = suppliedHandle;
                    const slots = {
                        default: () => {
                            renders++;
                            return h('output');
                        },
                    };
                    return () =>
                        selected.value
                            ? h(
                                  DataAdapter<{ adapter: DataAdapterHandle }>,
                                  { adapter: external },
                                  slots,
                              )
                            : h(DataAdapter<{ source: string }>, { source: 'Products' }, slots);
                },
            }),
        );
        app.config.errorHandler = (error) => {
            errors.push(error);
        };
        app.mount(node());
        cleanups.push(() => app.unmount());
        await settle();
        const before = renders;
        selected.value = true;
        await settle();
        expect(errors.some((error) => String(error).includes('ownership mode cannot change'))).toBe(
            true,
        );
        expect(renders).toBe(before);
        const sourceRequest = request.mock.calls.find(([config]) =>
            config.url.endsWith('/Products'),
        )?.[0];
        const externalRequest = request.mock.calls.find(([config]) =>
            config.url.endsWith('/Other'),
        )?.[0];
        expect(sourceRequest?.signal?.aborted).toBe(true);
        expect(externalRequest?.signal?.aborted).toBe(false);
        owned.resolve(respond(page()));
        supplied.resolve(respond(page()));
        await settle();
        expect(suppliedHandle?.ready).toBe(true);
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('disposes and detaches a source-owning view when its stable id changes', async () => {
        const pending = deferred();
        request.mockReturnValue(pending.promise);
        const id = ref('catalog');
        const errors: unknown[] = [];
        let renders = 0;
        const app = renderer.createApp(
            defineComponent({
                setup: () => () =>
                    h(
                        DataAdapter<{ source: string; id: string }>,
                        { source: 'Products', id: id.value },
                        {
                            default: () => {
                                renders++;
                                return h('output');
                            },
                        },
                    ),
            }),
        );
        app.config.errorHandler = (error) => {
            errors.push(error);
        };
        app.mount(node());
        cleanups.push(() => app.unmount());
        await settle();
        const before = renders;
        id.value = 'other';
        await settle();
        expect(errors.some((error) => String(error).includes('id cannot change'))).toBe(true);
        expect(renders).toBe(before);
        expect(request.mock.calls[0]?.[0].signal?.aborted).toBe(true);
        pending.resolve(respond(page()));
        await settle();
        expect(renders).toBe(before);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('requires setup ownership and stable ids for reactive inputs', () => {
        expect(() => useDataAdapter('Products')).toThrow('during Vue setup');
        host(() => {
            expect(() => useDataAdapter(ref('Products'), { id: '' })).toThrow('stable id');
            expect(() => useDataAdapter('Products', { id: ' ' })).toThrow('must not be empty');
            return () => h('output');
        });
    });
});
