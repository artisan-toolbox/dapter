import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { useDataAdapter, type InfiniteControls } from '@artisan-toolbox/dapter';
import { h } from 'vue';
import { advancedIntent, advancedPage, mountAdvanced } from './phase4-fixtures.js';
import { deferred, intent, mount, mountTree, page, settle } from './phase2-fixtures.js';

const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
function controls(value: InfiniteControls | null): InfiniteControls {
    if (!value) throw new Error('Missing infinite controls');
    return value;
}
function create(options: Parameters<typeof mountAdvanced>[0] = {}) {
    const created = mountAdvanced({ ...options, protocol: 2, infinite: options.infinite ?? true });
    cleanups.push(created.unmount);
    return created;
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
it.each(['simple', 'cursor'] as const)(
    'appends losslessly with actual %s boundaries and tail counts',
    async (mode) => {
        const first = advancedIntent(
            mode === 'cursor'
                ? { mode, cursor: null, per_page: 2 }
                : { mode, current: 1, per_page: 2 },
        );
        const second = advancedIntent(
            mode === 'cursor'
                ? { mode, cursor: 'next-token', per_page: 2 }
                : { mode, current: 2, per_page: 2 },
        );
        request
            .mockResolvedValueOnce(respond(advancedPage(first, { data: [{ id: 1 }, { id: 2 }] })))
            .mockResolvedValueOnce(
                respond(advancedPage(second, { data: [{ id: 1 }], next: null })),
            );
        const { handle } = create();
        await settle();
        expect(handle.data).toEqual([{ id: 1 }, { id: 2 }]);
        const operation = controls(handle.infinite).append();
        expect(controls(handle.infinite).append()).toBe(operation);
        expect(handle.infinite?.status).toBe('loading');
        expect(await operation).toEqual({ status: 'success' });
        expect(handle.data).toEqual([{ id: 1 }, { id: 2 }, { id: 1 }]);
        expect(handle.accepted?.paging).toEqual(second.paging);
        expect(handle.paging.total).toBeNull();
        expect(handle.infinite?.status).toBe('exhausted');
        await controls(handle.infinite).append();
        expect(request).toHaveBeenCalledTimes(2);
    },
);
it('supports offset appends, clamping and ordinary navigation as a replacement window', async () => {
    request.mockResolvedValueOnce(respond(page()));
    const { handle, unmount } = mount({ protocol: 2, infinite: true, url: false });
    cleanups.push(unmount);
    await settle();
    request.mockResolvedValueOnce(
        respond(page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(4);
    request.mockResolvedValueOnce(
        respond(page(intent({ paging: { mode: 'offset', current: 1, per_page: 2 } }))),
    );
    await handle.paging.goToPage(1);
    await settle();
    expect(handle.data).toHaveLength(2);
    request.mockResolvedValueOnce(respond(page()));
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(2);
    expect(handle.infinite?.status).toBe('exhausted');
});
it('consumes empty simple boundaries and stops cursor cycles without requesting them', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create();
    await settle();
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 }), {
                next: 'again',
            }),
        ),
    );
    await controls(handle.infinite).append();
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'again', per_page: 2 }), {
                next: 'next-token',
            }),
        ),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(6);
    await controls(handle.infinite).append();
    expect(request).toHaveBeenCalledTimes(3);
    expect(handle.infinite?.status).toBe('exhausted');
});
it('pauses a failed boundary until explicit retry and retains the accepted window', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create();
    await settle();
    const accepted = handle.data;
    request.mockResolvedValueOnce(
        respond({ version: 2, error: { kind: 'server', code: 'oops', message: 'Failed' } }, 500),
    );
    expect((await controls(handle.infinite).append()).status).toBe('failure');
    expect(handle.data).toBe(accepted);
    expect(handle.infinite?.status).toBe('paused');
    expect((await controls(handle.infinite).append()).status).toBe('superseded');
    expect(request).toHaveBeenCalledTimes(2);
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 }), {
                next: null,
            }),
        ),
    );
    expect((await controls(handle.infinite).retry()).status).toBe('success');
    expect(handle.data).toHaveLength(4);
});
it('restarts expired retry, reload and changed criteria at the first position', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create();
    await settle();
    request.mockResolvedValueOnce(
        respond(
            {
                version: 2,
                error: {
                    kind: 'cursor_expired',
                    code: 'cursor_expired',
                    message: 'The cursor has expired. Restart pagination to continue.',
                    recovery: 'restart',
                },
            },
            409,
        ),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(2);
    request.mockResolvedValueOnce(respond(advancedPage()));
    await controls(handle.infinite).retry();
    await settle();
    expect(JSON.parse(String(request.mock.lastCall?.[0].data)).state.paging.cursor).toBeNull();
    expect(handle.data).toHaveLength(2);
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 })),
        ),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(4);
    const response = deferred<HttpResponse>();
    request.mockReturnValueOnce(response.promise);
    const refresh = handle.paging.reload();
    expect(handle.data).toHaveLength(0);
    await settle();
    expect(JSON.parse(String(request.mock.lastCall?.[0].data)).state.paging.cursor).toBeNull();
    response.resolve(respond(advancedPage()));
    await refresh;
});
it('rejects page preservation atomically and clears old rows before criteria dispatch, including failure', async () => {
    request.mockResolvedValueOnce(respond(page()));
    const { handle, unmount } = mount({ protocol: 2, infinite: true, url: false });
    cleanups.push(unmount);
    await settle();
    const old = handle.requested;
    expect(
        (
            await handle.fields?.name?.search?.call(
                { value: 'new', matchType: 'contains' },
                { page: 'preserve' },
            )
        )?.status,
    ).toBe('failure');
    expect(handle.requested).toBe(old);
    expect(handle.data).toHaveLength(2);
    expect(request).toHaveBeenCalledTimes(1);
    request.mockResolvedValueOnce(
        respond({ version: 2, error: { kind: 'server', code: 'oops', message: 'Failed' } }, 500),
    );
    const edit = handle.fields?.name?.search?.call({ value: 'new', matchType: 'contains' });
    expect(handle.data).toHaveLength(0);
    await edit;
    expect(handle.data).toHaveLength(0);
    expect(handle.infinite?.status).toBe('paused');
});
it('supersedes append across context replacement and disposal and rejects unrelated active work', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle, source, unmount } = create();
    await settle();
    const old = controls(handle.infinite);
    const response = deferred<HttpResponse>();
    request.mockReturnValueOnce(response.promise);
    const append = old.append();
    await settle();
    request.mockResolvedValueOnce(respond(advancedPage()));
    source.value = 'Other';
    expect(await append).toEqual({ status: 'superseded' });
    response.resolve(respond(advancedPage()));
    await settle();
    expect(handle.data).toHaveLength(2);
    expect(await old.append()).toEqual({ status: 'superseded' });
    const active = controls(handle.infinite);
    unmount();
    expect(await active.retry()).toEqual({ status: 'superseded' });
});
it('leaves legacy mode disabled and validates explicit protocol opt in', () => {
    const unmount = mountTree(() => {
        const legacy = useDataAdapter('Legacy', { infinite: false });
        expect(legacy.infinite).toBeNull();
        return () => h('output');
    });
    cleanups.push(unmount);
});
it('retains old loaded action targets and coalesces only identical action lookup pairs', async () => {
    const actions = (id: number) => ({
        save: {
            method: 'patch',
            transports: ['http'],
            target: {
                input: 'id',
                valueType: 'integer',
                values: [{ value: id, url: `https://example.test/save/${id}` }],
            },
        },
    });
    request.mockResolvedValueOnce(respond({ ...advancedPage(), actions: actions(1) }));
    const { handle } = create();
    await settle();
    request.mockResolvedValueOnce(
        respond({
            ...advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 }), {
                next: 'third',
            }),
            actions: actions(2),
        }),
    );
    await controls(handle.infinite).append();
    expect(handle.actions?.available.save?.target?.values).toEqual([
        { value: 1, url: 'https://example.test/save/1' },
        { value: 2, url: 'https://example.test/save/2' },
    ]);
    request.mockResolvedValueOnce(respond({ saved: true }));
    expect((await handle.actions?.http('save', { id: 1 }))?.status).toBe('completed');
    expect(request.mock.lastCall?.[0].url).toBe('https://example.test/save/1');
    request.mockResolvedValueOnce(
        respond({
            ...advancedPage(advancedIntent({ mode: 'cursor', cursor: 'third', per_page: 2 }), {
                next: null,
            }),
            actions: actions(1),
        }),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(6);
    expect(handle.actions?.available.save?.target?.values).toHaveLength(2);
});
const saveRow = {
    method: 'patch',
    transports: ['http'],
    target: { input: 'id', valueType: 'integer', values: [{ value: 1, url: '/save/1' }] },
};
const markRow = {
    method: 'post',
    transports: ['http'],
    target: { input: 'id', valueType: 'integer', values: [{ value: 3, url: '/mark/3' }] },
};
const refresh = { method: 'post', transports: ['http'], target: null, url: '/refresh' };
it.each([
    {
        name: 'eligible first page',
        first: { save: saveRow },
        second: undefined,
        expected: { save: saveRow },
    },
    {
        name: 'eligible second page',
        first: undefined,
        second: { mark: markRow },
        expected: { mark: markRow },
    },
    {
        name: 'disjoint row actions with a shared global',
        first: { save: saveRow, refresh },
        second: { mark: markRow, refresh },
        expected: { save: saveRow, mark: markRow, refresh },
    },
])(
    'retains every loaded row and exactly its advertised actions for $name',
    async ({ first, second, expected }) => {
        const next = advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 });
        request
            .mockResolvedValueOnce(respond({ ...advancedPage(), actions: first }))
            .mockResolvedValueOnce(
                respond({
                    ...advancedPage(next, { data: [{ id: 3 }, { id: 4 }] }),
                    actions: second,
                }),
            );
        const { handle } = create();
        await settle();
        expect(await controls(handle.infinite).append()).toEqual({ status: 'success' });
        expect(handle.data).toEqual([{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }]);
        expect(handle.actions?.available).toEqual(expected);
        for (const id of ['save', 'mark'])
            expect(await handle.actions?.http(id, { id: 4 })).toMatchObject({
                status: 'rejected',
                commit: 'not_started',
            });
        expect(request).toHaveBeenCalledTimes(2);
    },
);
it.each([
    { name: 'removed global', first: { refresh }, second: undefined },
    { name: 'added global', first: undefined, second: { refresh } },
    {
        name: 'changed global URL',
        first: { refresh },
        second: { refresh: { ...refresh, url: '/other' } },
    },
    { name: 'row target became global', first: { save: saveRow }, second: { save: refresh } },
])('rejects incompatible windows with a $name', async ({ first, second }) => {
    const next = advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 });
    request
        .mockResolvedValueOnce(respond({ ...advancedPage(), actions: first }))
        .mockResolvedValueOnce(respond({ ...advancedPage(next), actions: second }));
    const { handle } = create();
    await settle();
    expect(await controls(handle.infinite).append()).toMatchObject({
        status: 'failure',
        error: { kind: 'protocol' },
    });
    expect(handle.data).toEqual([]);
    expect(handle.actions).toBeNull();
});
it.each([
    'url',
    'method',
    'transport',
    'input',
    'value type',
    'records',
    'channel',
    'capabilities',
] as const)('rejects incompatible %s before mixing windows', async (change) => {
    const action = {
        method: 'patch',
        transports: ['http'],
        target: {
            input: 'id',
            valueType: 'integer',
            values: [{ value: 1, url: 'https://example.test/save/1' }],
        },
    };
    request.mockResolvedValueOnce(respond({ ...advancedPage(), actions: { save: action } }));
    const { handle } = create();
    await settle();
    const next = advancedPage(
        advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 }),
    );
    const changed =
        change === 'url'
            ? {
                  ...action,
                  target: {
                      ...action.target,
                      values: [{ value: 1, url: 'https://example.test/different' }],
                  },
              }
            : change === 'method'
              ? { ...action, method: 'delete' }
              : change === 'transport'
                ? { ...action, transports: ['visit'] }
                : change === 'input'
                  ? { ...action, target: { ...action.target, input: 'other' } }
                  : change === 'value type'
                    ? {
                          ...action,
                          target: {
                              ...action.target,
                              valueType: 'string',
                              values: [{ value: '1', url: '/save/1' }],
                          },
                      }
                    : action;
    const metadata =
        change === 'records'
            ? { records: { mode: 'identified', rows: [{ id: 1 }, { id: 2 }] }, channel: null }
            : change === 'channel'
              ? {
                    records: { mode: 'identified', rows: [{ id: 1 }, { id: 2 }] },
                    channel: { type: 'public', name: 'changed', event: '.dapter.updated' },
                }
              : next.metadata;
    request.mockResolvedValueOnce(
        respond({
            ...next,
            metadata,
            ...(change === 'capabilities'
                ? { capabilities: { ...next.capabilities, fields: {} } }
                : {}),
            actions: { save: changed },
        }),
    );
    expect((await controls(handle.infinite).append()).status).toBe('failure');
    expect(handle.error?.kind).toBe('protocol');
    expect(handle.data).toEqual([]);
    expect(handle.infinite?.status).toBe('paused');
});
it('consumes empty simple pages without confusing row count and boundary progress', async () => {
    const first = advancedIntent({ mode: 'simple', current: 1, per_page: 2 });
    request.mockResolvedValueOnce(respond(advancedPage(first)));
    const { handle } = create();
    await settle();
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'simple', current: 2, per_page: 2 }), { data: [] }),
        ),
    );
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(2);
    expect(handle.paging.current).toBe(2);
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'simple', current: 3, per_page: 2 }), {
                data: [],
                next: null,
            }),
        ),
    );
    await controls(handle.infinite).append();
    expect(handle.paging.current).toBe(3);
    expect(handle.infinite?.status).toBe('exhausted');
    expect(handle.data).toHaveLength(2);
});
it('replaces accumulated pages when explicitly navigating to the already accepted tail', async () => {
    request.mockResolvedValueOnce(respond(page()));
    const { handle, unmount } = mount({ protocol: 2, infinite: true, url: false });
    cleanups.push(unmount);
    await settle();
    const second = page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }));
    request.mockResolvedValueOnce(respond(second));
    await controls(handle.infinite).append();
    expect(handle.data).toHaveLength(4);
    request.mockResolvedValueOnce(respond(second));
    await handle.paging.goToPage(2);
    await settle();
    expect(handle.data).toHaveLength(2);
    expect(handle.paging.current).toBe(2);
    expect(request).toHaveBeenCalledTimes(3);
});
it('accepts every latest search and filter intent while rows are cleared, including after failure', async () => {
    vi.useFakeTimers();
    request.mockResolvedValueOnce(respond(page()));
    const { handle, unmount } = mount({
        protocol: 2,
        infinite: true,
        url: false,
        searchDebounce: 100,
    });
    cleanups.push(unmount);
    await settle();
    const captured = handle.fields?.name?.search;
    const first = captured?.call({ value: 'a', matchType: 'contains' });
    expect(handle.data).toHaveLength(0);
    expect(handle.actions).toBeNull();
    expect(handle.fields?.name?.search).not.toBeNull();
    const second = handle.fields?.name?.search?.call({ value: 'ab', matchType: 'contains' });
    expect(await first).toEqual({ status: 'superseded' });
    const third = captured?.call({ value: 'abc', matchType: 'contains' });
    expect(await second).toEqual({ status: 'superseded' });
    request.mockResolvedValueOnce(
        respond({ version: 2, error: { kind: 'server', code: 'oops', message: 'Failed' } }, 500),
    );
    await vi.advanceTimersByTimeAsync(100);
    expect((await third)?.status).toBe('failure');
    expect(handle.data).toHaveLength(0);
    const current = intent({
        fields: {
            name: { search: { value: 'abcd', matchType: 'contains' } },
            status: { filters: { selected: { value: 'active' } } },
        },
    });
    const correction = handle.fields?.name?.search?.call({ value: 'abcd', matchType: 'contains' });
    request.mockResolvedValueOnce(respond(page(current)));
    const filter = handle.fields?.status?.filters?.selected?.call({ value: 'active' });
    expect(await correction).toEqual({ status: 'superseded' });
    expect((await filter)?.status).toBe('success');
    expect(handle.requested).toEqual(current);
    expect(handle.data).toHaveLength(2);
    expect(request).toHaveBeenCalledTimes(3);
});
