import Echo from 'laravel-echo';
import {
    router,
    http,
    HttpNetworkError,
    type HttpRequestConfig,
    type HttpResponse,
} from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { h, watch } from 'vue';
import { useDataAdapter, type DataAdapterHandle } from '@artisan-toolbox/dapter';
import readCorpus from '../../resources/contracts/read-v1.json';
import updatesCorpus from '../../resources/contracts/updates-v1.json';
import type {
    ChannelDescriptor,
    DapterEcho,
    DapterEchoChannel,
    DataAdapterOptions,
} from '@artisan-toolbox/dapter';
import { parsePageV2 } from '../../resources/js/protocol-v2.js';
import { parseChannel, parseUpdate } from '../../resources/js/records.js';
import { deferred, intent, mount, mountTree, page, settle } from './phase2-fixtures.js';

const descriptor: ChannelDescriptor = {
    type: 'private',
    name: 'products.reader',
    event: '.dapter.updated',
};
class Channel implements DapterEchoChannel {
    readonly listeners = new Set<(value: unknown) => void>();
    readonly errors = new Set<(value: unknown) => void>();
    readonly stopped: ((value: unknown) => void)[] = [];
    listen(_event: string, callback: (value: unknown) => void) {
        this.listeners.add(callback);
    }
    stopListening(_event: string, callback: (value: unknown) => void) {
        this.stopped.push(callback);
        this.listeners.delete(callback);
    }
    error(callback: (value: unknown) => void) {
        this.errors.add((value) => callback(value));
    }
    emit(value: unknown) {
        [...this.listeners].forEach((callback) => callback(value));
    }
    fail() {
        [...this.errors].forEach((callback) => callback(new Error('Denied')));
    }
}
function echo(): { echo: DapterEcho; channel: Channel } {
    const channel = new Channel();
    return { channel, echo: { private: () => channel, channel: () => channel } };
}
function live(state = intent(), revision = '1', channel: ChannelDescriptor | null = descriptor) {
    return {
        ...page(state),
        metadata: {
            records: {
                mode: 'revisioned',
                format: 'uint64-decimal',
                rows: [
                    { id: 1, revision },
                    { id: '1', revision },
                ],
            },
            channel,
        },
    };
}
let sequence = 0;
function update(id: number | string = 1, revision = '2', data: unknown = { name: 'Updated' }) {
    return {
        version: 1,
        eventId: `00000000-0000-0000-0000-${String(++sequence).padStart(12, '0')}`,
        source: 'Products',
        channel: descriptor,
        data: [data],
        records: { mode: 'revisioned', format: 'uint64-decimal', rows: [{ id, revision }] },
    };
}
const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
function create(
    options: Omit<
        Extract<DataAdapterOptions, { protocol: 2; paging?: 'offset' | undefined }>,
        'protocol'
    > = {},
    setup?: Parameters<typeof mount>[1],
) {
    const owner = mount({ ...options, protocol: 2 }, setup);
    cleanups.push(owner.unmount);
    return owner;
}
beforeEach(() => {
    sequence = 0;
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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
});

describe('shared PHP and TypeScript update corpus', () => {
    it.each(updatesCorpus.cases)('agrees on $name', ({ envelope, valid }) => {
        let accepted = false;
        try {
            const channel = parseChannel(envelope.channel);
            if (channel) accepted = parseUpdate(envelope, 'Orders', channel) !== null;
        } catch {
            accepted = false;
        }
        expect(accepted).toBe(valid);
    });
});

describe('live projected rows', () => {
    it('accepts typed identities and uint64 extremes without requiring projected IDs', () => {
        const fixture = { ...live(), data: ['numeric', 'string'] };
        expect(parsePageV2(fixture).metadata).toEqual(fixture.metadata);
        expect(parsePageV2(live(intent(), '18446744073709551615')).metadata.records.mode).toBe(
            'revisioned',
        );
        for (const revision of ['01', '-1', '18446744073709551616', '1e3', '', '1\n'])
            expect(() => parsePageV2(live(intent(), revision))).toThrow();
        const duplicate = live();
        duplicate.metadata.records.rows[1] = { id: 1, revision: '1' };
        expect(() => parsePageV2(duplicate)).toThrow();
        expect(() =>
            parsePageV2({ ...live(), metadata: { ...live().metadata, extra: true } }),
        ).toThrow();
    });
    it('replaces only a loaded typed ID in place and preserves listing and action ownership', async () => {
        const bus = echo();
        request.mockResolvedValue(respond(live()));
        const decoder = vi.fn((row: unknown) => {
            if (
                typeof row !== 'object' ||
                row === null ||
                !('name' in row) ||
                typeof row.name !== 'string'
            )
                throw new Error('Invalid');
            return row.name.toUpperCase();
        });
        const { handle } = create({ echo: bus.echo, decodeRow: decoder });
        await settle();
        const paging = JSON.stringify(handle.paging),
            requested = handle.requested,
            accepted = handle.accepted;
        bus.channel.emit(update('1', '18446744073709551615', { name: 'String ID' }));
        expect(handle.data).toEqual(['PRODUCT', 'STRING ID']);
        bus.channel.emit(update('1', '9007199254740993', { name: 'Stale' }));
        bus.channel.emit(update(99, '9'));
        expect(handle.data).toEqual(['PRODUCT', 'STRING ID']);
        expect(JSON.stringify(handle.paging)).toEqual(paging);
        expect(handle.requested).toBe(requested);
        expect(handle.accepted).toBe(accepted);
        expect(request).toHaveBeenCalledTimes(1);
        expect(decoder).toHaveBeenCalledTimes(5);
    });
    it('rejects invalid metadata before decoding and keeps rejected event rows intact', async () => {
        const bus = echo(),
            decoder = vi.fn((row: unknown) => {
                if (row === 'bad') throw new Error('Invalid');
                return row;
            });
        request.mockResolvedValue(respond(live()));
        const { handle } = create({ echo: bus.echo, decodeRow: decoder });
        await settle();
        const old = handle.data;
        bus.channel.emit({ ...update(), records: { mode: 'unidentified' } });
        expect(handle.integrationError?.code).toBe('invalid_event');
        expect(handle.data).toBe(old);
        expect(decoder).toHaveBeenCalledTimes(2);
        bus.channel.emit(update(1, '2', 'bad'));
        expect(handle.integrationError?.code).toBe('decoder_failed');
        expect(handle.data).toBe(old);
        bus.channel.emit({ ...update(), source: 'Foreign' });
        expect(decoder).toHaveBeenCalledTimes(3);
        bus.channel.emit(update(1, '2', { name: 'Good' }));
        expect(handle.data[0]).toEqual({ name: 'Good' });
        expect(handle.integrationError?.code).toBe('decoder_failed');
        await handle.paging.reload();
        await settle();
        expect(handle.integrationError).toBeNull();
    });
    it('rejects invalid HTTP metadata before invoking any row decoder', async () => {
        const fixture = live();
        fixture.metadata.records.rows[1] = { id: 1, revision: '1' };
        request.mockResolvedValueOnce(respond(fixture));
        const decoder = vi.fn((value: unknown) => value);
        const { handle } = create({ decodeRow: decoder });
        await settle();
        expect(decoder).not.toHaveBeenCalled();
        expect(handle.ready).toBe(false);
        expect(handle.error?.kind).toBe('protocol');
        expect(handle.metadata).toBeNull();
    });
    it('accepts the server row depth boundary and rejects one level beyond it', async () => {
        const bus = echo();
        let row: unknown = 'depth-100';
        for (let depth = 0; depth < 100; depth++) row = [row];
        request.mockResolvedValueOnce(respond({ ...live(), data: [row, 'other'] }));
        const { handle } = create({ echo: bus.echo });
        await settle();
        expect(handle.ready).toBe(true);
        expect(handle.data[0]).toEqual(row);
        bus.channel.emit(update(1, '2', row));
        await settle();
        expect(handle.metadata?.records).toMatchObject({ rows: [{ id: 1, revision: '2' }, {}] });
        const accepted = handle.data;
        bus.channel.emit(update(1, '3', [row]));
        expect(handle.integrationError?.code).toBe('invalid_event');
        expect(handle.data).toBe(accepted);
        request.mockResolvedValueOnce(respond({ ...live(), data: [[row], 'other'] }));
        expect(await handle.paging.reload()).toMatchObject({ status: 'failure' });
        expect(handle.error?.kind).toBe('protocol');
    });
    it.each([64, 65])(
        'enforces the server channel segment boundary at %i characters',
        async (length) => {
            const bus = echo();
            const channel = { ...descriptor, name: `products.${'x'.repeat(length)}` };
            request.mockResolvedValueOnce(respond(live(intent(), '1', channel)));
            const { handle } = create({ echo: bus.echo });
            await settle();
            expect(handle.ready).toBe(length === 64);
            expect(bus.channel.listeners.size).toBe(length === 64 ? 1 : 0);
            expect(handle.error?.kind ?? null).toBe(length === 64 ? null : 'protocol');
        },
    );
    it('retains native action metadata and previously acquired action controls through a replacement', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(
            respond({
                ...live(),
                actions: {
                    save: {
                        method: 'post',
                        transports: ['http'],
                        target: null,
                        url: 'https://example.test/actions/save',
                    },
                },
            }),
        );
        const { handle } = create({ echo: bus.echo });
        await settle();
        const actions = handle.actions;
        if (!actions) throw new Error('Missing action metadata');
        bus.channel.emit(update(1, '9', 'updated'));
        await settle();
        expect(request).toHaveBeenCalledTimes(1);
        request.mockResolvedValueOnce(respond({ saved: true }));
        expect(await actions.http('save', {})).toMatchObject({
            status: 'completed',
            data: { saved: true },
        });
        expect(request.mock.calls[1]?.[0].url).toBe('https://example.test/actions/save');
    });
    it('deduplicates accepted event IDs and isolates two owners on the same channel', async () => {
        const bus = echo();
        request.mockResolvedValue(respond(live()));
        const first = create({ echo: bus.echo, id: 'first' }),
            second = create({ echo: bus.echo, id: 'second' });
        await settle();
        expect(bus.channel.listeners.size).toBe(2);
        expect(bus.channel.errors.size).toBe(1);
        const event = update();
        bus.channel.emit(event);
        bus.channel.emit({ ...event, data: ['duplicate'] });
        expect(second.handle.data[0]).toEqual({ name: 'Updated' });
        first.unmount();
        expect(bus.channel.listeners.size).toBe(1);
        bus.channel.fail();
        expect(second.handle.integrationError?.code).toBe('subscription_failed');
        expect(bus.channel.listeners.size).toBe(0);
        expect(first.handle.integrationError).toBeNull();
        await second.handle.paging.reload();
        await settle();
        expect(bus.channel.listeners.size).toBe(1);
        expect(bus.channel.errors.size).toBe(1);
    });
    it('removes owner callbacks through installed Echo while retaining its single wrapped error bridge', async () => {
        const callbacks = new Map<string, Set<(value: unknown) => void>>();
        const bind = (event: string, callback: (value: unknown) => void) => {
            let listeners = callbacks.get(event);
            if (!listeners) {
                listeners = new Set();
                callbacks.set(event, listeners);
            }
            listeners.add(callback);
        };
        const unbind = vi.fn((event: string, callback: (value: unknown) => void) =>
            callbacks.get(event)?.delete(callback),
        );
        const unsubscribe = vi.fn();
        const installed = new Echo({
            broadcaster: 'pusher',
            key: 'test',
            withoutInterceptors: true,
            client: { subscribe: () => ({ bind, unbind }), unsubscribe },
        });
        request.mockResolvedValue(respond(live()));
        const first = create({ echo: installed, id: 'echo-one' }),
            second = create({ echo: installed, id: 'echo-two' });
        await settle();
        expect(callbacks.get('dapter.updated')?.size).toBe(2);
        expect(callbacks.get('pusher:subscription_error')?.size).toBe(1);
        first.unmount();
        expect(callbacks.get('dapter.updated')?.size).toBe(1);
        expect(unsubscribe).not.toHaveBeenCalled();
        for (const listener of callbacks.get('pusher:subscription_error') ?? [])
            listener({ status: 403 });
        expect(second.handle.integrationError?.code).toBe('subscription_failed');
        expect(callbacks.get('dapter.updated')?.size).toBe(0);
        second.unmount();
        expect(unbind.mock.calls.every((call) => typeof call[1] === 'function')).toBe(true);
        expect(callbacks.get('pusher:subscription_error')?.size).toBe(1);
        expect(unsubscribe).not.toHaveBeenCalled();
    });
    it('rejects detached callbacks after explicit subscription recovery', async () => {
        const bus = echo();
        request.mockResolvedValue(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const old = [...bus.channel.listeners][0];
        if (!old) throw new Error('Missing original listener');
        bus.channel.fail();
        expect(bus.channel.listeners.size).toBe(0);
        await handle.paging.reload();
        await settle();
        expect(bus.channel.listeners.size).toBe(1);
        expect(handle.integrationError).toBeNull();
        const accepted = handle.data;
        old(update(1, '9', 'detached'));
        expect(handle.data).toBe(accepted);
        bus.channel.emit(update(1, '2', 'current'));
        expect(handle.data[0]).toBe('current');
    });
    it('buffers through winning reads and discards events after a failed read', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const read = deferred<HttpResponse>();
        request.mockReturnValueOnce(read.promise);
        const reload = handle.paging.reload();
        await settle();
        bus.channel.emit(update(1, '3', 'buffered'));
        expect(handle.data[0]).not.toBe('buffered');
        read.resolve(respond(live(intent(), '2')));
        await reload;
        await settle();
        expect(handle.data[0]).toBe('buffered');
        const failed = deferred<HttpResponse>();
        request.mockReturnValueOnce(failed.promise);
        const failure = handle.paging.reload();
        await settle();
        bus.channel.emit(update(1, '4', 'discarded'));
        failed.resolve(
            respond(
                { version: 2, error: { kind: 'server', code: 'oops', message: 'Failed' } },
                500,
            ),
        );
        await failure;
        await settle();
        expect(handle.data[0]).toBe('buffered');
        bus.channel.emit(update(1, '5', 'future'));
        expect(handle.data[0]).toBe('future');
    });
    it('drains events received while buffered replacements render before polling resumes', async () => {
        vi.useFakeTimers();
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo, polling: { interval: 100 } }, (owner) => {
            watch(
                () => owner.data[0],
                (row) => {
                    if (row === 'buffered') bus.channel.emit(update(1, '4', 'during-render'));
                },
                { flush: 'post' },
            );
        });
        await settle();
        const read = deferred<HttpResponse>();
        request.mockReturnValueOnce(read.promise);
        const reload = handle.paging.reload();
        await settle();
        bus.channel.emit(update(1, '3', 'buffered'));
        read.resolve(respond(live(intent(), '2')));
        await reload;
        await settle();
        expect(handle.data[0]).toBe('during-render');
        expect(handle.metadata?.records).toMatchObject({ rows: [{ id: 1, revision: '4' }, {}] });
        expect(handle.integrationError).toBeNull();
        expect(request).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(99);
        expect(request).toHaveBeenCalledTimes(2);
    });
    it.each([
        { events: 130, payloadBytes: 0 },
        { events: 20, payloadBytes: 60000 },
    ])(
        'bounds reentrant rendering across a publication for $events events and $payloadBytes byte rows',
        async ({ events, payloadBytes }) => {
            vi.useFakeTimers();
            const bus = echo();
            const payload = 'x'.repeat(payloadBytes);
            request.mockResolvedValue(respond(live()));
            const { handle } = create({ echo: bus.echo }, (owner) => {
                watch(
                    () => owner.data[0],
                    (row) => {
                        if (
                            typeof row === 'object' &&
                            row !== null &&
                            'marker' in row &&
                            typeof row.marker === 'number' &&
                            row.marker < events
                        ) {
                            const marker = row.marker + 1;
                            bus.channel.emit(update(1, String(marker + 2), { marker, payload }));
                        }
                    },
                    { flush: 'post' },
                );
            });
            await settle();
            bus.channel.emit(update(1, '2', { marker: 0, payload }));
            await vi.advanceTimersByTimeAsync(0);
            await settle();
            expect(handle.integrationError?.code).toBe('event_overflow');
            expect(bus.channel.listeners.size).toBe(0);
            expect(handle.ready).toBe(true);
            expect(handle.data[0]).not.toEqual({ marker: events, payload });
            expect(request).toHaveBeenCalledTimes(1);
            await handle.paging.reload();
            await settle();
            expect(handle.integrationError).toBeNull();
            expect(bus.channel.listeners.size).toBe(1);
        },
    );
    it('retains same-context events for a superseding request and never reuses stale context callbacks', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const old = [...bus.channel.listeners][0];
        if (!old) throw new Error('Missing listener');
        const abandoned = deferred<HttpResponse>(),
            winner = deferred<HttpResponse>();
        request.mockReturnValueOnce(abandoned.promise).mockReturnValueOnce(winner.promise);
        const first = handle.paging.reload();
        await settle();
        bus.channel.emit(update(1, '5', 'winner'));
        const second = handle.fields?.name?.search?.call({
            value: 'different',
            matchType: 'contains',
        });
        await settle();
        const requested = handle.requested;
        if (!requested || requested.version !== 2) throw new Error('Missing request');
        winner.resolve(respond(live(requested)));
        await second;
        await settle();
        expect(await first).toEqual({ status: 'superseded' });
        expect(handle.data[0]).toBe('winner');
        request.mockResolvedValueOnce(respond(live()));
        const reset = handle.resetContext();
        expect(handle.data).toEqual([]);
        expect(bus.channel.listeners.size).toBe(0);
        await reset;
        await settle();
        old(update(1, '6', 'stale'));
        expect(handle.data[0]).not.toBe('stale');
        abandoned.resolve(respond(live()));
    });
    it('suspends overflow without recovery requests until an explicit successful reload', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const waiting = deferred<HttpResponse>();
        request.mockReturnValueOnce(waiting.promise);
        const read = handle.paging.reload();
        await settle();
        for (let index = 0; index < 129; index++) bus.channel.emit(update(1, String(index + 2)));
        expect(handle.integrationError?.code).toBe('event_overflow');
        expect(bus.channel.listeners.size).toBe(0);
        expect(request).toHaveBeenCalledTimes(2);
        waiting.resolve(respond(live()));
        await read;
        await settle();
        expect(handle.integrationError).toBeNull();
        expect(bus.channel.listeners.size).toBe(1);
    });
    it('keeps an overflowing infinite channel suspended across automatic window replacement', async () => {
        vi.useFakeTimers();
        const bus = echo();
        request.mockResolvedValue(respond(live()));
        const { handle } = create({ echo: bus.echo, polling: { interval: 100 }, infinite: true });
        await settle();
        const [stale] = bus.channel.listeners;
        if (!stale) throw new Error('Missing original listener');
        const waiting = deferred<HttpResponse>();
        request.mockReturnValueOnce(waiting.promise);
        const append = handle.infinite?.append();
        await settle();
        for (let index = 0; index < 129; index++) bus.channel.emit(update(1, String(index + 2)));
        waiting.resolve(
            respond(live(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
        );
        expect(await append).toEqual({ status: 'success' });
        expect(handle.data).toHaveLength(4);
        expect(handle.integrationError?.code).toBe('event_overflow');
        expect(bus.channel.listeners.size).toBe(0);
        await vi.advanceTimersByTimeAsync(100);
        await settle();
        expect(request).toHaveBeenCalledTimes(3);
        expect(handle.data).toEqual(live().data);
        expect(handle.integrationError?.code).toBe('event_overflow');
        expect(bus.channel.listeners.size).toBe(0);
        request.mockResolvedValueOnce(
            respond(
                {
                    version: 2,
                    error: { kind: 'server', code: 'unavailable', message: 'Retry later.' },
                },
                500,
            ),
        );
        expect(await handle.paging.reload()).toMatchObject({ status: 'failure' });
        expect(handle.integrationError?.code).toBe('event_overflow');
        expect(bus.channel.listeners.size).toBe(0);
        expect(await handle.paging.reload()).toEqual({ status: 'success' });
        await settle();
        expect(handle.integrationError).toBeNull();
        expect(bus.channel.listeners.size).toBe(1);
        stale(update(1, '999', 'obsolete'));
        expect(handle.data).toEqual(live().data);
        bus.channel.emit(update(1, '2', 'current'));
        expect(handle.data[0]).toBe('current');
    });
    it('recovers a suspended subscription when explicit reload coalesces with and flushes a debounced read', async () => {
        vi.useFakeTimers();
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo, searchDebounce: 100 });
        await settle();
        bus.channel.fail();
        expect(handle.integrationError?.code).toBe('subscription_failed');
        const state = intent({
            fields: { name: { search: { value: 'fresh', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(live(state)));
        const edit = handle.fields?.name?.search?.call({ value: 'fresh', matchType: 'contains' });
        const retry = handle.paging.reload();
        expect(retry).toBe(edit);
        await retry;
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(handle.integrationError).toBeNull();
        expect(bus.channel.listeners.size).toBe(1);
    });
    it('bounds deduplication to 512 accepted event IDs without conflating revisions', async () => {
        const bus = echo();
        request.mockResolvedValue(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const first = update(1, '2', 'first');
        bus.channel.emit(first);
        await settle();
        let retained = first;
        for (let index = 0; index < 512; index++) {
            retained = update(1, '2', 'same-revision');
            bus.channel.emit(retained);
        }
        bus.channel.emit({
            ...retained,
            data: ['duplicate'],
            records: { ...retained.records, rows: [{ id: 1, revision: '3' }] },
        });
        expect(handle.data[0]).toBe('first');
        bus.channel.emit({
            ...first,
            data: ['evicted'],
            records: { ...first.records, rows: [{ id: 1, revision: '3' }] },
        });
        expect(handle.data[0]).toBe('evicted');
    });
    it('bounds pending UTF-8 bytes independently from the 128-event limit', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const waiting = deferred<HttpResponse>();
        request.mockReturnValueOnce(waiting.promise);
        const read = handle.paging.reload();
        await settle();
        for (let index = 0; index < 18; index++)
            bus.channel.emit(update(1, String(index + 2), 'a'.repeat(60000)));
        expect(handle.integrationError?.code).toBe('event_overflow');
        expect(bus.channel.listeners.size).toBe(0);
        waiting.resolve(respond(live()));
        await read;
        await settle();
    });
    it('rejects callbacks from an earlier descriptor even after that descriptor returns', async () => {
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo });
        await settle();
        const old = [...bus.channel.listeners][0];
        if (!old) throw new Error('Missing callback');
        request.mockResolvedValueOnce(
            respond(live(intent(), '1', { ...descriptor, name: 'products.other' })),
        );
        await handle.paging.reload();
        await settle();
        request.mockResolvedValueOnce(respond(live()));
        await handle.paging.reload();
        await settle();
        old(update(1, '9', 'stale'));
        expect(handle.data[0]).not.toBe('stale');
        bus.channel.emit(update(1, '9', 'current'));
        expect(handle.data[0]).toBe('current');
    });
    it('reports missing Echo and detaches on access loss', async () => {
        request.mockResolvedValueOnce(respond(live()));
        const missing = create();
        await settle();
        expect(missing.handle.integrationError?.code).toBe('echo_missing');
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo, id: 'protected' });
        await settle();
        request.mockResolvedValueOnce(
            respond(
                { version: 2, error: { kind: 'access', code: 'access_denied', message: 'Denied' } },
                403,
            ),
        );
        await handle.paging.reload();
        await settle();
        expect(handle.ready).toBe(false);
        expect(bus.channel.listeners.size).toBe(0);
    });
    it('validates channel segments and deeply nested or cyclic row inputs without recursion overflow', () => {
        for (const name of ['.start', 'end.', 'two..dots', 'newline\n'])
            expect(() =>
                parsePageV2({
                    ...live(),
                    metadata: { ...live().metadata, channel: { ...descriptor, name } },
                }),
            ).toThrow();
        expect(
            parsePageV2({
                ...live(),
                metadata: { ...live().metadata, channel: { ...descriptor, name: 'safe_.token=-' } },
            }).metadata.channel?.name,
        ).toBe('safe_.token=-');
        const cycle: unknown[] = [];
        cycle.push(cycle);
        expect(() => parseUpdate(update(1, '2', cycle), 'Products', descriptor)).toThrow(
            'Invalid row depth',
        );
    });
    it('rejects exact envelope violations, Unicode, unsafe numbers, oversized bytes, and mode mismatch', () => {
        for (const bad of [
            { ...update(), extra: true },
            { ...update(), eventId: 'UPPER' },
            { ...update(), eventId: update().eventId + '\n' },
            update(1, '2', '\uD800'),
            update(1, '2', Number.MAX_SAFE_INTEGER + 1),
            update(1, '2', 'a'.repeat(65536)),
        ])
            expect(() => parseUpdate(bad, 'Products', descriptor)).toThrow();
    });
});

describe('creator compatibility and context reset', () => {
    it('keeps protocol 1 additions honest and rejects previously saved reset closures', async () => {
        vi.useFakeTimers();
        const fixture = readCorpus.successes[0];
        if (!fixture) throw new Error('Missing v1 fixture');
        request.mockResolvedValue(respond(fixture.response));
        let handle: DataAdapterHandle | undefined;
        cleanups.push(
            mountTree(() => {
                handle = useDataAdapter('Products');
                return () => h('output');
            }),
        );
        await settle();
        if (!handle) throw new Error('Missing legacy owner');
        expect(handle.integrationError).toBeNull();
        const timers = vi.getTimerCount();
        handle.restartPolling();
        expect(vi.getTimerCount()).toBe(timers);
        const saved = handle.resetContext;
        const reset = handle.resetContext();
        expect(handle.data).toEqual([]);
        await reset;
        await settle();
        expect(JSON.parse(String(request.mock.calls.at(-1)?.[0].data))).toEqual({ version: 1 });
        expect(await saved()).toEqual({ status: 'superseded' });
    });
    it.each(['source change', 'context reset'] as const)(
        'supersedes all retained protocol 1 commands after a %s',
        async (change) => {
            const fixture = readCorpus.successes[0];
            if (!fixture) throw new Error('Missing v1 fixture');
            request.mockResolvedValue(respond(fixture.response));
            const { handle, source, unmount } = mount({ protocol: 1 });
            cleanups.push(unmount);
            await settle();
            const paging = handle.paging;
            const reset = handle.resetState;
            expect(await paging.reload()).toEqual({ status: 'success' });
            expect(await paging.reload()).toEqual({ status: 'success' });
            expect(request).toHaveBeenCalledTimes(3);
            if (change === 'source change') source.value = 'Archive';
            else await handle.resetContext();
            await settle();
            expect(request).toHaveBeenCalledTimes(4);
            for (const command of [
                paging.reload,
                paging.goToFirst,
                paging.goToLast,
                paging.goToNext,
                paging.goToPrevious,
                () => paging.goToPage(1),
                () => paging.per_page.setValue(2),
                reset,
            ]) {
                expect(await command()).toEqual({ status: 'superseded' });
            }
            expect(request).toHaveBeenCalledTimes(4);
        },
    );
    it('removes only its saved URL namespace and starts fresh defaults at a known context boundary', async () => {
        const url = new URL('https://example.test/catalog?other=kept#rows');
        url.searchParams.set('dapter[other-owner]', 'other-snapshot');
        url.searchParams.set(
            'dapter[context-owner]',
            JSON.stringify({
                version: 2,
                source: 'Products',
                state: intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }),
            }),
        );
        const location = { href: url.href };
        vi.stubGlobal('window', Object.assign(new EventTarget(), { location }));
        vi.stubGlobal('document', new EventTarget());
        vi.spyOn(router, 'replace').mockImplementation((options) => {
            location.href = String(options.url);
            const callback = Reflect.get(options, 'onFinish');
            if (typeof callback === 'function') Reflect.apply(callback, undefined, []);
        });
        request.mockResolvedValueOnce(
            respond(page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
        );
        const { handle } = create({ id: 'context-owner', url: true });
        await settle();
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const saved = handle.paging.reload;
        const reset = handle.resetContext();
        expect(handle.ready).toBe(false);
        expect(handle.actions).toBeNull();
        expect(handle.requested).toBeNull();
        await settle();
        expect(JSON.parse(String(request.mock.calls.at(-1)?.[0].data))).toEqual({ version: 2 });
        expect(new URL(location.href).searchParams.has('dapter[context-owner]')).toBe(false);
        expect(new URL(location.href).searchParams.get('dapter[other-owner]')).toBe(
            'other-snapshot',
        );
        expect(new URL(location.href).searchParams.get('other')).toBe('kept');
        expect(new URL(location.href).hash).toBe('#rows');
        expect(await saved()).toEqual({ status: 'superseded' });
        pending.resolve(respond(page()));
        await reset;
        await settle();
    });
    it.each([
        null,
        0,
        true,
        { interval: 0 },
        { interval: -1 },
        { interval: Number.NaN },
        { interval: Infinity },
        { interval: 2147483648 },
        { interval: 1, extra: true },
    ])('rejects invalid polling option %j through the public creator', (polling) => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        expect(() =>
            mountTree(() => {
                Reflect.apply(useDataAdapter, undefined, ['Products', { protocol: 2, polling }]);
                return () => h('output');
            }),
        ).toThrow();
    });
});

describe('render-aware optional polling', () => {
    it('owns no default timer and validates exact positive fractional intervals', async () => {
        vi.useFakeTimers();
        request.mockResolvedValue(respond(page()));
        const owner = create();
        await settle();
        expect(vi.getTimerCount()).toBe(1);
        owner.handle.restartPolling();
        expect(vi.getTimerCount()).toBe(1);
        const fractional = create({ polling: { interval: 0.5 }, id: 'fractional' });
        await settle();
        expect(vi.getTimerCount()).toBe(3);
        fractional.unmount();
        expect(vi.getTimerCount()).toBe(2);
    });
    it('polls latest requested intent and waits for settlement instead of flushing search debounce', async () => {
        vi.useFakeTimers();
        request.mockResolvedValueOnce(respond(page()));
        const { handle } = create({ polling: { interval: 100 }, searchDebounce: 200 });
        await settle();
        expect(vi.getTimerCount()).toBe(2);
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const edit = handle.fields?.name?.search?.call({ value: 'new', matchType: 'contains' });
        await settle();
        await vi.advanceTimersByTimeAsync(100);
        expect(request).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(100);
        expect(request).toHaveBeenCalledTimes(2);
        expect(vi.getTimerCount()).toBe(1);
        const requested = handle.requested;
        if (!requested || requested.version !== 2) throw new Error('Missing request');
        pending.resolve(respond(page(requested)));
        await edit;
        await settle();
        expect(vi.getTimerCount()).toBe(3);
        request.mockResolvedValueOnce(respond(page(requested)));
        await vi.advanceTimersByTimeAsync(100);
        await settle();
        expect(request).toHaveBeenCalledTimes(3);
        expect(JSON.parse(String(request.mock.calls[2]?.[0].data)).state).toEqual(requested);
    });
    it('holds replay and polling until browser publication and Vue rendering settle', async () => {
        vi.useFakeTimers();
        const location = { href: 'https://example.test/catalog?other=kept#rows' };
        vi.stubGlobal('window', Object.assign(new EventTarget(), { location }));
        vi.stubGlobal('document', Object.assign(new EventTarget(), { visibilityState: 'visible' }));
        let finish: (() => void) | null = null;
        vi.spyOn(router, 'replace').mockImplementation((options) => {
            location.href = String(options.url);
            const callback = Reflect.get(options, 'onFinish');
            if (typeof callback === 'function')
                finish = () => Reflect.apply(callback, undefined, []);
        });
        const bus = echo();
        request.mockResolvedValueOnce(respond(live()));
        const { handle } = create({ echo: bus.echo, url: true, polling: { interval: 100 } });
        await settle();
        const state = intent({
            fields: { name: { search: { value: 'changed', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(live(state)));
        const edit = handle.fields?.name?.search?.call({ value: 'changed', matchType: 'contains' });
        await edit;
        await settle();
        bus.channel.emit(update(1, '4', 'after-render'));
        expect(handle.data[0]).not.toBe('after-render');
        await vi.advanceTimersByTimeAsync(1000);
        expect(request).toHaveBeenCalledTimes(2);
        if (finish === null) throw new Error('Missing publication callback');
        const complete: () => void = finish;
        complete();
        await settle();
        expect(handle.data[0]).toBe('after-render');
        expect(new URL(location.href).searchParams.get('other')).toBe('kept');
        expect(new URL(location.href).hash).toBe('#rows');
        const waiting = deferred<HttpResponse>();
        request.mockReturnValueOnce(waiting.promise);
        await vi.advanceTimersByTimeAsync(99);
        expect(request).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(request).toHaveBeenCalledTimes(3);
        waiting.resolve(respond(live(state)));
        await settle();
    });
    it('exposes render failure separately and pauses polling without an unhandled publication rejection', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        let fail = false;
        request.mockResolvedValue(respond(page()));
        const { handle } = create({ polling: { interval: 100 } }, (owner) => {
            watch(
                () => owner.data,
                () => {
                    if (fail) throw new Error('Render failed');
                },
                { flush: 'pre' },
            );
        });
        await settle();
        fail = true;
        expect(await handle.paging.reload()).toEqual({ status: 'success' });
        fail = false;
        await settle().catch((cause: unknown) => expect(cause).toBeInstanceOf(Error));
        await settle();
        expect(handle.integrationError?.code).toBe('render_failed');
        expect(handle.error).toBeNull();
        expect(handle.ready).toBe(true);
        await vi.advanceTimersByTimeAsync(1000);
        expect(request).toHaveBeenCalledTimes(2);
        await handle.paging.reload();
        await settle();
        expect(handle.integrationError).toBeNull();
    });
    it('owns zero polling timers while hidden or offline and resumes one future interval without catch-up', async () => {
        vi.useFakeTimers();
        const documentState = Object.assign(new EventTarget(), { visibilityState: 'hidden' });
        const network = { onLine: true };
        const browser = Object.assign(new EventTarget(), {
            location: { href: 'https://example.test/' },
        });
        vi.stubGlobal('window', browser);
        vi.stubGlobal('document', documentState);
        vi.stubGlobal('navigator', network);
        request.mockResolvedValue(respond(page()));
        create({ polling: { interval: 100 } });
        await settle();
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(500);
        expect(request).toHaveBeenCalledTimes(1);
        documentState.visibilityState = 'visible';
        documentState.dispatchEvent(new Event('visibilitychange'));
        expect(vi.getTimerCount()).toBe(2);
        network.onLine = false;
        browser.dispatchEvent(new Event('offline'));
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(500);
        expect(request).toHaveBeenCalledTimes(1);
        network.onLine = true;
        browser.dispatchEvent(new Event('online'));
        expect(vi.getTimerCount()).toBe(2);
        await vi.advanceTimersByTimeAsync(99);
        expect(request).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        documentState.visibilityState = 'hidden';
        documentState.dispatchEvent(new Event('visibilitychange'));
        expect(vi.getTimerCount()).toBe(2);
    });
    it('pauses after failure, restarts with one future interval, and disposes every timer', async () => {
        vi.useFakeTimers();
        request.mockResolvedValueOnce(respond(page()));
        const owner = create({ polling: { interval: 100 } });
        await settle();
        request.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        await vi.advanceTimersByTimeAsync(100);
        await settle();
        expect(owner.handle.error?.kind).toBe('network');
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(request).toHaveBeenCalledTimes(2);
        owner.handle.restartPolling();
        expect(vi.getTimerCount()).toBe(2);
        expect(request).toHaveBeenCalledTimes(2);
        request.mockResolvedValueOnce(respond(page()));
        await vi.advanceTimersByTimeAsync(100);
        await settle();
        expect(owner.handle.error).toBeNull();
        expect(vi.getTimerCount()).toBe(3);
        owner.unmount();
        expect(vi.getTimerCount()).toBe(2);
    });
});
