import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { watch } from 'vue';
import { deferred, settle } from './phase2-fixtures.js';
import { advancedIntent, advancedPage, mountAdvanced } from './phase4-fixtures.js';
import type { DapterEcho, InfiniteControls } from '@artisan-toolbox/dapter';
const rendering = vi.hoisted(() => ({ hold: null as Promise<void> | null }));
vi.mock('vue', async (load) => {
    const actual = await load<typeof import('vue')>();
    return {
        ...actual,
        nextTick: (...args: Parameters<typeof actual.nextTick>) =>
            rendering.hold
                ? rendering.hold.then(() => actual.nextTick(...args))
                : actual.nextTick(...args),
    };
});
const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
const channel = { type: 'public', name: 'phase-five', event: '.dapter.updated' } as const;
function bus() {
    const listeners = new Set<(value: unknown) => void>();
    const receiver = {
        listen: (_name: string, callback: (value: unknown) => void) => listeners.add(callback),
        stopListening: (_name: string, callback: (value: unknown) => void) =>
            listeners.delete(callback),
        error() {},
    };
    const echo: DapterEcho = { private: () => receiver, channel: () => receiver };
    return {
        echo,
        emit: (value: unknown) => [...listeners].forEach((callback) => callback(value)),
        listeners,
    };
}
function live(cursor: string | null = null, revision = '1', next: string | null = 'next-token') {
    const fixture = advancedPage(advancedIntent({ mode: 'cursor', cursor, per_page: 2 }), {
        data: [{ value: revision }, { value: 'string' }],
        next,
    });
    return {
        ...fixture,
        metadata: {
            records: {
                mode: 'revisioned',
                format: 'uint64-decimal',
                rows: [
                    { id: 1, revision },
                    { id: '1', revision: '1' },
                ],
            },
            channel,
        },
    };
}
let eventId = 0;
function update(revision: string, value: string) {
    return {
        version: 1,
        source: 'Feed',
        eventId: `00000000-0000-4000-8000-${String(++eventId).padStart(12, '0')}`,
        channel,
        data: [{ value }],
        records: { mode: 'revisioned', format: 'uint64-decimal', rows: [{ id: 1, revision }] },
    };
}
function infinite(value: InfiniteControls | null) {
    if (!value) throw new Error('Missing infinite controls');
    return value;
}
function create(options: Partial<Parameters<typeof mountAdvanced>[0]> = {}) {
    const created = mountAdvanced({ ...options, protocol: 2, infinite: true });
    cleanups.push(created.unmount);
    return created;
}
async function flush() {
    for (let i = 0; i < 40; i++) await Promise.resolve();
}
beforeEach(() => {
    request.mockReset();
    http.setClient({ request });
    rendering.hold = null;
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
    rendering.hold = null;
    cleanups.splice(0).forEach((stop) => stop());
    vi.useRealTimers();
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
});
it('holds append through publication, Vue rendering, and every buffered live replay', async () => {
    const events = bus();
    request.mockResolvedValueOnce(respond(live()));
    const { handle } = create({ echo: events.echo });
    await settle();
    const rendered = deferred<void>();
    rendering.hold = rendered.promise;
    request.mockResolvedValueOnce(respond(live('next-token', '3', 'third')));
    const operation = infinite(handle.infinite).append();
    let completed = false;
    void operation.then(() => {
        completed = true;
    });
    await flush();
    expect(handle.data).toHaveLength(4);
    expect(completed).toBe(false);
    expect(infinite(handle.infinite).append()).toBe(operation);
    expect(request).toHaveBeenCalledTimes(2);
    events.emit(update('2', 'revision-two'));
    expect(handle.data[0]).toEqual({ value: '1' });
    rendering.hold = null;
    rendered.resolve();
    expect(await operation).toEqual({ status: 'success' });
    expect(handle.data).toEqual([
        { value: 'revision-two' },
        { value: 'string' },
        { value: '3' },
        { value: 'string' },
    ]);
    expect(handle.metadata?.records).toMatchObject({
        rows: [{ id: 1, revision: '2' }, { id: '1' }, { id: 1, revision: '3' }, { id: '1' }],
    });
    events.emit(update('4', 'all-numeric'));
    await settle();
    expect(handle.data).toEqual([
        { value: 'all-numeric' },
        { value: 'string' },
        { value: 'all-numeric' },
        { value: 'string' },
    ]);
});
it('holds restart retry through rendering and restarts after post-commit rendering failure', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create();
    await settle();
    const failedRender = deferred<void>();
    rendering.hold = failedRender.promise.then(() => {
        throw new Error('Renderer failed');
    });
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 })),
        ),
    );
    const append = infinite(handle.infinite).append();
    await flush();
    rendering.hold = null;
    failedRender.resolve();
    expect((await append).status).toBe('failure');
    expect(handle.infinite?.status).toBe('paused');
    expect(handle.data).toHaveLength(4);
    const rendered = deferred<void>();
    rendering.hold = rendered.promise;
    request.mockResolvedValueOnce(respond(advancedPage()));
    const retry = infinite(handle.infinite).retry();
    let finished = false;
    void retry.then(() => {
        finished = true;
    });
    await flush();
    expect(JSON.parse(String(request.mock.lastCall?.[0].data)).state.paging.cursor).toBeNull();
    expect(handle.data).toHaveLength(2);
    expect(finished).toBe(false);
    expect(infinite(handle.infinite).retry()).toBe(retry);
    rendering.hold = null;
    rendered.resolve();
    expect(await retry).toEqual({ status: 'success' });
    expect(handle.data).toHaveLength(2);
});
it('preserves ordinary promise timing and rejects append during unrelated publication', async () => {
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create();
    await settle();
    const rendered = deferred<void>();
    rendering.hold = rendered.promise;
    request.mockResolvedValueOnce(respond(advancedPage()));
    expect(await handle.paging.reload()).toEqual({ status: 'success' });
    expect((await infinite(handle.infinite).append()).status).toBe('superseded');
    rendering.hold = null;
    rendered.resolve();
    await settle();
});
it('detaches live callbacks while reload clears the window without false invalid-event errors', async () => {
    const events = bus();
    request.mockResolvedValueOnce(respond(live()));
    const { handle } = create({ echo: events.echo });
    await settle();
    const old = [...events.listeners][0];
    const pending = deferred<HttpResponse>();
    request.mockReturnValueOnce(pending.promise);
    const reload = handle.paging.reload();
    old?.(update('9', 'obsolete'));
    expect(handle.data).toHaveLength(0);
    expect(handle.integrationError).toBeNull();
    pending.resolve(respond(live()));
    await reload;
    await settle();
    expect(handle.data[0]).toEqual({ value: '1' });
    expect(handle.integrationError).toBeNull();
});
it('reschedules polling after append settlement and reloads the first window', async () => {
    vi.useFakeTimers();
    request.mockResolvedValueOnce(respond(advancedPage()));
    const { handle } = create({ polling: { interval: 100 } });
    await settle();
    const rendered = deferred<void>();
    rendering.hold = rendered.promise;
    request.mockResolvedValueOnce(
        respond(
            advancedPage(advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 })),
        ),
    );
    const append = infinite(handle.infinite).append();
    await flush();
    await vi.advanceTimersByTimeAsync(200);
    expect(request).toHaveBeenCalledTimes(2);
    rendering.hold = null;
    rendered.resolve();
    await append;
    request.mockResolvedValueOnce(respond(advancedPage()));
    await vi.advanceTimersByTimeAsync(100);
    expect(request).toHaveBeenCalledTimes(3);
    expect(JSON.parse(String(request.mock.lastCall?.[0].data)).state.paging.cursor).toBeNull();
    await settle();
    expect(handle.data).toHaveLength(2);
});
it('settles live events emitted while replayed replacements render', async () => {
    const events = bus();
    request.mockResolvedValueOnce(respond(live()));
    const { handle } = create({ echo: events.echo });
    await settle();
    cleanups.push(
        watch(
            () => handle.data[0],
            (row) => {
                if (
                    typeof row === 'object' &&
                    row !== null &&
                    'value' in row &&
                    row.value === 'first-replay'
                )
                    events.emit(update('5', 'second-replay'));
            },
            { flush: 'post' },
        ),
    );
    const rendered = deferred<void>();
    rendering.hold = rendered.promise;
    request.mockResolvedValueOnce(respond(live('next-token')));
    const operation = infinite(handle.infinite).append();
    await flush();
    events.emit(update('4', 'first-replay'));
    rendering.hold = null;
    rendered.resolve();
    await operation;
    expect(handle.data[0]).toEqual({ value: 'second-replay' });
    expect(handle.data[2]).toEqual({ value: 'second-replay' });
});
