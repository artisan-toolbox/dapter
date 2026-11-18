import {
    http,
    router,
    type HttpRequestConfig,
    type HttpResponse,
    type VisitOptions,
    type Errors,
} from '@inertiajs/core';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vite-plus/test';
import { defineActions, useDataAdapter, type DataAdapterHandle } from '@artisan-toolbox/dapter';
import corpus from '../../resources/contracts/read-v1.json';
import { parseActions } from '../../resources/js/actions';
import { mount, mountTree, page, settle, deferred } from './phase2-fixtures';
const original = http.getClient(),
    request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>(),
    cleanups: (() => void)[] = [];
const metadata = {
    save: {
        method: 'patch',
        transports: ['http', 'visit'],
        target: {
            input: 'id',
            valueType: 'integer',
            values: [{ value: 1, url: 'https://example.test/dapter/actions/Products/save/1' }],
        },
    },
    refresh: {
        method: 'post',
        transports: ['http'],
        url: 'https://example.test/dapter/actions/Products/refresh',
        target: null,
    },
};
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
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
    cleanups.splice(0).forEach((cleanup) => cleanup());
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});
function create(options: Parameters<typeof mount>[0] = {}) {
    const result = mount(options);
    cleanups.push(result.unmount);
    return result;
}
async function ready(options: Parameters<typeof mount>[0] = {}) {
    request.mockResolvedValueOnce(respond({ ...page(), actions: metadata }));
    const mounted = create(options);
    await settle();
    return mounted;
}
const decodeSaved = (value: unknown) => {
    if (typeof value !== 'object' || value === null || !('saved' in value) || value.saved !== true)
        throw new Error('Invalid save result');
    return { saved: true } as const;
};
describe('authorized native actions', () => {
    it('parses only generated endpoints and declared primitive selectors', () => {
        expect(parseActions(metadata).save?.target).toMatchObject({
            input: 'id',
            valueType: 'integer',
        });
        for (const value of [
            {},
            { save: { ...metadata.save, url: '/forged' } },
            { save: { ...metadata.save, transports: ['http', 'http'] } },
            {
                save: {
                    ...metadata.save,
                    target: { ...metadata.save.target, values: [{ value: '1', url: '/wrong' }] },
                },
            },
            { save: { ...metadata.refresh, url: 'javascript:alert(1)' } },
        ])
            expect(() => parseActions(value)).toThrow();
    });
    it('supports the default v1 owner without enabling v2 read controls', async () => {
        const legacy = corpus.successes[0];
        if (!legacy) throw new Error('Missing corpus');
        request.mockResolvedValueOnce(respond({ ...legacy.response, actions: metadata }));
        let handle: DataAdapterHandle | undefined;
        cleanups.push(
            mountTree(() => {
                handle = useDataAdapter('Products');
                return () => null as never;
            }),
        );
        await settle();
        expect(handle?.global).toBeNull();
        expect(handle?.actions?.available.save).toBeTruthy();
        request.mockResolvedValueOnce(respond({ saved: true }));
        expect(await handle?.actions?.http('save', { id: 1 })).toEqual({
            status: 'completed',
            commit: 'confirmed',
            data: { saved: true },
        });
        expect(request.mock.calls[0]?.[0].data).toBe('{"version":1}');
    });
    it('dispatches fresh independent writes, shares pending counts, and leaves listing state intact', async () => {
        const { handle } = await ready(),
            before = {
                data: handle.data,
                requested: handle.requested,
                accepted: handle.accepted,
                paging: handle.paging.current,
            };
        const first = deferred<HttpResponse>(),
            second = deferred<HttpResponse>();
        request.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const one = handle.actions?.http('save', { id: 1, name: 'Lin' }),
            two = handle.actions?.http('save', { id: 1, name: 'Lin' });
        expect(handle.actions?.pending).toBe(2);
        expect(request).toHaveBeenCalledTimes(3);
        expect(request.mock.calls[1]?.[0]).toMatchObject({
            method: 'patch',
            url: metadata.save.target.values[0]?.url,
            headers: {
                'X-Dapter-Action': '1',
                'X-Dapter-Transport': 'http',
                Accept: 'application/json',
            },
        });
        first.resolve(respond({ saved: true }));
        await one;
        expect(handle.actions?.pending).toBe(1);
        second.resolve(respond({ saved: true }));
        await two;
        expect(handle.actions?.pending).toBe(0);
        expect({
            data: handle.data,
            requested: handle.requested,
            accepted: handle.accepted,
            paging: handle.paging.current,
        }).toEqual(before);
        expect(handle.status).toBe('success');
        expect(handle.error).toBeNull();
    });
    it('restricts local ids/transports and decodes input/result without broadening server permission', async () => {
        const actionDefinitions = defineActions({
            save: {
                transports: ['http'],
                decodeInput: (value: unknown) => {
                    if (
                        typeof value !== 'object' ||
                        value === null ||
                        !('id' in value) ||
                        typeof value.id !== 'number'
                    )
                        throw new Error('Invalid input');
                    return { id: value.id };
                },
                decodeResult: decodeSaved,
            },
            invented: { transports: ['http'] },
        });
        const { handle } = await ready({ actionDefinitions });
        expect(Object.keys(handle.actions?.available ?? {})).toEqual(['save']);
        expect(await handle.actions?.http('invented', {})).toMatchObject({
            status: 'rejected',
            commit: 'not_started',
        });
        expect(await handle.actions?.http('save', { id: '1' })).toMatchObject({
            status: 'rejected',
            commit: 'not_started',
        });
        request.mockResolvedValueOnce(respond({ saved: true }));
        expect(await handle.actions?.http('save', { id: 1 })).toEqual({
            status: 'completed',
            commit: 'confirmed',
            data: { saved: true },
        });
        request.mockResolvedValueOnce(respond({ saved: false }));
        expect(await handle.actions?.http('save', { id: 1 })).toMatchObject({
            status: 'failed',
            commit: 'unknown',
        });
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('rejects typed selector mismatches and reserved JavaScript overrides before native dispatch', async () => {
        const { handle } = await ready();
        for (const input of [
            { id: '1' },
            { id: 2 },
            { other: 1 },
            new FormData(),
            { id: 1, callback: () => {} },
        ])
            expect(await handle.actions?.http('save', input)).toMatchObject({
                status: 'rejected',
                commit: 'not_started',
            });
        for (const options of [
            { headers: {} },
            { url: '/other' },
            { method: 'post' },
            { data: {} },
            { optimistic: () => ({}) },
            { preserveScroll: true },
        ])
            expect(await handle.actions?.http('save', { id: 1 }, options as never)).toMatchObject({
                status: 'rejected',
                commit: 'not_started',
            });
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('records mutate-then-422 as validation with unknown commit when native SDK resolves undefined', async () => {
        const { handle } = await ready();
        let mutations = 0;
        const onError = vi.fn<(errors: Errors) => void>(),
            onSuccess = vi.fn(),
            onFinish = vi.fn();
        request.mockImplementationOnce(async () => {
            mutations++;
            return respond({ errors: { name: ['Already changed'] } }, 422);
        });
        expect(
            await handle.actions?.http('save', { id: 1 }, { onError, onSuccess, onFinish }),
        ).toEqual({
            status: 'validation',
            commit: 'unknown',
            errors: { name: ['Already changed'] },
        });
        expect(mutations).toBe(1);
        expect(onError).toHaveBeenCalledWith({ name: ['Already changed'] });
        expect(onSuccess).not.toHaveBeenCalled();
        expect(onFinish).toHaveBeenCalledTimes(1);
        expect(handle.actions?.validation).toEqual({ name: ['Already changed'] });
        expect(handle.actions?.pending).toBe(0);
        expect(handle.error).toBeNull();
    });
    it.each(['validation', 'cancellation', 'finish'] as const)(
        'keeps %s callback exceptions failed with unknown commit',
        async (stage) => {
            const { handle } = await ready();
            const failure = new Error('User callback failed');
            const fail = () => {
                throw failure;
            };
            if (stage === 'cancellation') {
                request.mockImplementationOnce(async () => {
                    throw new DOMException('Aborted', 'AbortError');
                });
            } else
                request.mockResolvedValueOnce(
                    respond({ errors: { name: ['Already changed'] } }, 422),
                );
            const result = await handle.actions?.http(
                'save',
                { id: 1 },
                {
                    ...(stage === 'validation' ? { onError: fail } : {}),
                    ...(stage === 'cancellation' ? { onCancel: fail } : {}),
                    ...(stage === 'finish' ? { onFinish: fail } : {}),
                },
            );
            expect(result).toEqual({ status: 'failed', commit: 'unknown', error: failure });
            expect(request).toHaveBeenCalledTimes(2);
            expect(handle.actions?.pending).toBe(0);
            expect(handle.error).toBeNull();
        },
    );
    it('distinguishes local onBefore rejection, real cancellation, server, malformed JSON and callback failures', async () => {
        const { handle } = await ready();
        const finish = vi.fn();
        expect(
            await handle.actions?.http(
                'save',
                { id: 1 },
                { onBefore: () => false, onFinish: finish },
            ),
        ).toMatchObject({ status: 'rejected', commit: 'not_started' });
        expect(finish).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(1);
        request.mockImplementationOnce(async (config) => {
            if (config.signal?.aborted) throw new DOMException('Aborted', 'AbortError');
            return respond({});
        });
        const cancelled = vi.fn();
        expect(
            await handle.actions?.http(
                'save',
                { id: 1 },
                { onCancelToken: (token) => token.cancel(), onCancel: cancelled },
            ),
        ).toMatchObject({ status: 'cancelled', commit: 'unknown' });
        expect(cancelled).toHaveBeenCalledTimes(1);
        const exception = vi.fn();
        request.mockResolvedValueOnce(respond({ message: 'Broken' }, 500));
        expect(
            await handle.actions?.http('save', { id: 1 }, { onHttpException: exception }),
        ).toMatchObject({ status: 'failed', commit: 'unknown' });
        expect(exception).toHaveBeenCalledTimes(1);
        const network = vi.fn();
        request.mockResolvedValueOnce({ status: 200, data: '{', headers: {} });
        expect(
            await handle.actions?.http('save', { id: 1 }, { onNetworkError: network }),
        ).toMatchObject({ status: 'failed', commit: 'unknown' });
        expect(network).toHaveBeenCalledTimes(1);
        request.mockResolvedValueOnce(respond({ saved: true }));
        expect(
            await handle.actions?.http(
                'save',
                { id: 1 },
                {
                    onSuccess: () => {
                        throw new Error('Callback failed');
                    },
                },
            ),
        ).toMatchObject({ status: 'failed', commit: 'unknown' });
        expect(handle.actions?.pending).toBe(0);
    });
    it('rechecks fresh metadata and prevents old source closures and late completions from repopulating state', async () => {
        const mounted = await ready(),
            saved = mounted.handle.actions;
        request.mockResolvedValueOnce(respond(page()));
        await mounted.handle.paging.reload();
        expect(mounted.handle.actions).toBeNull();
        expect(await saved?.http('save', { id: 1 })).toMatchObject({
            status: 'rejected',
            commit: 'not_started',
        });
        request.mockResolvedValueOnce(respond({ ...page(), actions: metadata }));
        await mounted.handle.paging.reload();
        const current = mounted.handle.actions,
            completion = deferred<HttpResponse>(),
            onSuccess = vi.fn();
        request.mockReturnValueOnce(completion.promise);
        const writing = current?.http('save', { id: 1 }, { onSuccess });
        mounted.unmount();
        completion.resolve(respond({ saved: true }));
        expect(await writing).toMatchObject({ status: 'completed', commit: 'confirmed' });
        expect(onSuccess).toHaveBeenCalledTimes(1);
        expect(mounted.handle.actions).toBeNull();
        expect(await current?.http('save', { id: 1 })).toMatchObject({
            status: 'rejected',
            commit: 'not_started',
        });
    });
    it('counts only dispatched writes when a native before listener cancels a visit', async () => {
        const { handle } = await ready();
        const dom = new EventTarget();
        vi.stubGlobal('document', dom);
        const cancel = vi.fn((event: Event) => event.preventDefault());
        dom.addEventListener('inertia:before', cancel);
        const response = deferred<HttpResponse>();
        request.mockReturnValueOnce(response.promise);
        const write = handle.actions?.http('save', { id: 1 });
        expect(handle.actions?.pending).toBe(1);
        const onStart = vi.fn(),
            onFinish = vi.fn();
        handle.actions?.visit('save', { id: 1 }, { onStart, onFinish });
        await settle();
        expect(cancel).toHaveBeenCalledOnce();
        expect(onStart).not.toHaveBeenCalled();
        expect(onFinish).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(2);
        expect(handle.actions?.pending).toBe(1);
        handle.actions?.visit('save', { id: 1 }, { onBefore: () => false });
        expect(handle.actions?.error).toBeInstanceOf(Error);
        expect(handle.actions?.pending).toBe(1);
        const failure = new Error('Preflight failed');
        handle.actions?.visit(
            'save',
            { id: 1 },
            {
                onBefore() {
                    throw failure;
                },
            },
        );
        expect(handle.actions?.error).toBe(failure);
        expect(handle.actions?.pending).toBe(1);
        expect(request).toHaveBeenCalledTimes(2);
        response.resolve(respond({ saved: true }));
        await write;
        expect(handle.actions?.pending).toBe(0);
    });
    it('delegates native visits with generated URL and native callbacks without a promise', async () => {
        const { handle } = await ready(),
            visit = vi.spyOn(router, 'visit').mockImplementation(() => {}),
            onSuccess = vi.fn();
        expect(
            handle.actions?.visit('save', { id: 1 }, { preserveScroll: true, onSuccess }),
        ).toBeUndefined();
        expect(visit).toHaveBeenCalledWith(
            metadata.save.target.values[0]?.url,
            expect.objectContaining({
                method: 'patch',
                data: { id: 1 },
                preserveScroll: true,
                headers: { 'X-Dapter-Action': '1', 'X-Dapter-Transport': 'visit' },
            }),
        );
        const options = visit.mock.calls[0]?.[1];
        const nativePage = { component: 'Done', props: {}, url: '/done', version: null };
        options?.onSuccess?.(nativePage as never);
        expect(onSuccess).toHaveBeenCalledWith(nativePage);
        options?.onFinish?.({} as never);
        expect(handle.actions?.pending).toBe(0);
        handle.actions?.visit('save', { id: 1 }, { headers: {} } as VisitOptions);
        expect(visit).toHaveBeenCalledTimes(1);
    });
    it('rejects disposal during input decoding and native before callbacks before dispatch', async () => {
        let dispose: (() => void) | undefined;
        const mounted = await ready({
            actionDefinitions: defineActions({
                save: {
                    transports: ['http'],
                    decodeInput(value: unknown) {
                        dispose?.();
                        return value;
                    },
                },
            }),
        });
        dispose = mounted.unmount;
        expect(await mounted.handle.actions?.http('save', { id: 1 })).toMatchObject({
            status: 'rejected',
            commit: 'not_started',
        });
        expect(request).toHaveBeenCalledTimes(1);
        const another = await ready();
        expect(
            await another.handle.actions?.http('save', { id: 1 }, { onBefore: another.unmount }),
        ).toMatchObject({ status: 'rejected', commit: 'not_started' });
        expect(request).toHaveBeenCalledTimes(2);
        const starting = await ready();
        expect(
            await starting.handle.actions?.http('save', { id: 1 }, { onStart: starting.unmount }),
        ).toMatchObject({ status: 'rejected', commit: 'not_started' });
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('records finish callback failures after native validation without double decrementing pending', async () => {
        const { handle } = await ready();
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {}),
            failure = new Error('Finish failed');
        handle.actions?.visit(
            'save',
            { id: 1 },
            {
                onFinish() {
                    throw failure;
                },
            },
        );
        const options = visit.mock.calls[0]?.[1];
        options?.onError?.({ name: 'Invalid' });
        expect(() => options?.onFinish?.({} as never)).toThrow(failure);
        expect(handle.actions?.error).toBe(failure);
        expect(handle.actions?.validation).toBeNull();
        expect(handle.actions?.pending).toBe(0);
    });
    it('rejects native before callback changes to owned visit properties and nested payloads', async () => {
        const { handle } = await ready();
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        for (const mutate of [
            (value: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) => {
                value.url = new URL('https://example.test/forged');
            },
            (value: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) => {
                value.method = 'delete';
            },
            (value: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) => {
                value.data = { id: 2 };
            },
            (value: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) => {
                value.headers = { 'X-Dapter-Transport': 'http' };
            },
            (value: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) => {
                Reflect.set(value.data, 'id', 2);
            },
        ]) {
            handle.actions?.visit('save', { id: 1 }, { onBefore: mutate });
            const options = visit.mock.calls.at(-1)?.[1];
            const pending = {
                url: new URL(metadata.save.target.values[0]?.url ?? ''),
                method: 'patch',
                data: { id: 1 },
                headers: options?.headers,
            };
            expect(options?.onBefore?.(pending as never)).toBe(false);
            expect(handle.actions?.error).toBeInstanceOf(Error);
            expect(handle.actions?.pending).toBe(0);
        }
    });
    it('preserves native multipart serialization and rejects native visit callback overrides', async () => {
        const { handle } = await ready();
        vi.stubGlobal('document', { dispatchEvent: () => true, addEventListener() {} });
        router.init({
            initialPage: {
                component: 'Products',
                props: {},
                url: 'https://example.test/products',
                version: null,
            } as never,
            resolveComponent: () => new Promise(() => {}),
            swapComponent: async () => {},
        });
        request.mockRejectedValueOnce(new Error('Stopped after native dispatch'));
        const started = vi.fn();
        handle.actions?.visit(
            'save',
            { id: 1, file: new Blob(['native file']) },
            {
                preserveUrl: true,
                onStart: () => started(handle.actions?.pending),
                onNetworkError: () => false,
            },
        );
        await settle();
        expect(started).toHaveBeenCalledWith(1);
        expect(handle.actions?.pending).toBe(0);
        expect(request).toHaveBeenCalledTimes(2);
        expect(request.mock.calls[1]?.[0].data).toBeInstanceOf(FormData);
        handle.actions?.visit(
            'save',
            { id: 1, file: new Blob(['native file']) },
            {
                preserveUrl: true,
                onBefore(visit) {
                    expect(visit.data).toBeInstanceOf(FormData);
                    if (visit.data instanceof FormData) visit.data.append('forged', 'true');
                },
            },
        );
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(handle.actions?.pending).toBe(0);
        expect(handle.actions?.error).toBeInstanceOf(Error);
    });
});
