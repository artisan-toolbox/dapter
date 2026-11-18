import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { DataAdapter, useDataAdapter } from '@artisan-toolbox/dapter';
import { h } from 'vue';
import {
    parsePageV2,
    parseDefinition,
    assertNarrowing,
    parseRequestedV2,
} from '../../resources/js/protocol-v2.js';
import { parseFailure, validationFailure } from '../../resources/js/protocol.js';
import { reduceRequested, type StateCommand } from '../../resources/js/requested-state.js';
import {
    advancedDefinition,
    advancedIntent,
    advancedPage,
    mountAdvanced,
    type SourceHandle,
} from './phase4-fixtures.js';
import { deferred, intent, mount, mountTree, page, settle } from './phase2-fixtures.js';

const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    data: JSON.stringify(data),
    headers: {},
});
const expired = {
    version: 2,
    error: {
        kind: 'cursor_expired',
        code: 'cursor_expired',
        message: 'The cursor has expired. Restart pagination to continue.',
        recovery: 'restart',
    },
};
function create(options: Parameters<typeof mountAdvanced>[0] = {}) {
    const result = mountAdvanced(options);
    cleanups.push(result.unmount);
    return result;
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

describe('source paging protocol', () => {
    it.each(['simple', 'cursor'] as const)(
        'accepts %s with unknown or exact count pairs without offset row arithmetic',
        (mode) => {
            for (const counts of ['unknown', 'exact'] as const) {
                const state = advancedIntent(
                    mode === 'simple'
                        ? { mode, current: 19, per_page: 2 }
                        : { mode, cursor: 'opaque', per_page: 2 },
                );
                const parsed = parsePageV2(advancedPage(state, { counts, data: [], next: null }));
                expect(parsed.paging).toMatchObject({
                    mode,
                    pages: null,
                    total: counts === 'exact' ? 6 : null,
                });
                expect(parsed.requested).toEqual(state);
                expect(Object.isFrozen(parsed.definition.paging)).toBe(true);
            }
        },
    );
    it.each([
        { paging: { current: 1 } },
        { paging: { pages: 1 } },
        { paging: { total: 0 } },
        { paging: { previous_cursor: 'previous' } },
        { paging: { has_next: false } },
        { paging: { cursor: 'different' } },
        { paging: { mode: 'simple' } },
        { paging: { next_cursor: '' } },
        { paging: { total: Number.MAX_SAFE_INTEGER + 1, filtered: 0 } },
    ])('rejects inconsistent cursor result %j', (patch) => {
        const fixture = advancedPage();
        expect(() =>
            parsePageV2({ ...fixture, paging: { ...fixture.paging, ...patch.paging } }),
        ).toThrow();
    });
    it('rejects mixed count policies, mode/size disagreement, unbounded rows and empty navigation', () => {
        const fixture = advancedPage();
        for (const bad of [
            { ...fixture, capabilities: advancedDefinition('cursor', 'exact') },
            { ...fixture, requested: advancedIntent({ mode: 'simple', current: 1, per_page: 2 }) },
            {
                ...fixture,
                state: { ...fixture.state, paging: { mode: 'cursor', cursor: null, per_page: 3 } },
            },
            { ...fixture, data: [{}, {}, {}] },
            { ...fixture, data: [] },
            {
                ...fixture,
                capabilities: {
                    ...fixture.capabilities,
                    paging: { ...fixture.capabilities.paging, expires_after: 901 },
                },
            },
        ])
            expect(() => parsePageV2(bad)).toThrow();
    });
    it('rejects decoder changes to mode, counts, lifetime and default size', () => {
        const source = parseDefinition(advancedDefinition());
        for (const raw of [
            advancedDefinition('simple'),
            advancedDefinition('cursor', 'exact'),
            { ...source, paging: { ...source.paging, per_page: 3 } },
        ]) {
            expect(() => assertNarrowing(source, parseDefinition(raw))).toThrow();
        }
        expect(() =>
            parseDefinition({ ...source, paging: { ...source.paging, expires_after: 1 } }),
        ).toThrow();
    });
    it('parses expiry exclusively at the v2 failure boundary and preserves the legacy four families', () => {
        expect(parseFailure(expired, 2)).toEqual(expired.error);
        expect(parseFailure({ ...expired, version: 1 })).toBeNull();
        expect(
            parseFailure({ ...expired, error: { ...expired.error, recovery: 'reload' } }, 2),
        ).toBeNull();
        expect(
            validationFailure(
                { _dapter_kind: ['validation'], _dapter_code: ['invalid_cursor'] },
                2,
            ),
        ).toMatchObject({ kind: 'validation', code: 'invalid_cursor' });
        expect(
            validationFailure({ _dapter_kind: ['validation'], _dapter_code: ['invalid_cursor'] }),
        ).toMatchObject({ kind: 'validation', code: 'request_rejected' });
    });
    it('keeps legacy offset wire and exact zero distinct from unknown counts', () => {
        expect(parsePageV2(page()).paging).toEqual(page().paging);
        const fixture = advancedPage(advancedIntent(), { counts: 'exact', data: [], next: null });
        expect(
            parsePageV2({ ...fixture, paging: { ...fixture.paging, total: 0, filtered: 0 } })
                .paging,
        ).toMatchObject({ total: 0, filtered: 0 });
    });
});

describe('source paging reducer', () => {
    const commands: StateCommand[] = [
        { kind: 'search', field: 'name', value: { value: 'Ada', matchType: 'contains' } },
        { kind: 'filter', field: 'status', id: 'selected', value: { value: 'active' } },
        { kind: 'order', field: 'name', value: { direction: 'desc', sortType: 'native' } },
        { kind: 'globalSearch', value: { value: 'Ada' } },
        { kind: 'globalFilter', id: 'status', value: { value: 'active' } },
        { kind: 'grouping', value: 'any' },
        { kind: 'size', value: 3 },
    ];
    it.each(commands)(
        'restarts cursor for real $kind changes and preserves unrelated criteria',
        (command) => {
            const current = advancedIntent({ mode: 'cursor', cursor: 'retained', per_page: 2 });
            const next = reduceRequested(current, command, advancedDefinition(), null);
            expect(next.paging).toMatchObject({ mode: 'cursor', cursor: null });
            expect(current.paging).toMatchObject({ cursor: 'retained' });
        },
    );
    it.each(['simple', 'cursor'] as const)(
        'rejects preserve even for exact %s counts and otherwise inactive clears',
        (mode) => {
            const state = advancedIntent(
                mode === 'simple'
                    ? { mode, current: 3, per_page: 2 }
                    : { mode, cursor: 'token', per_page: 2 },
            );
            expect(() =>
                reduceRequested(
                    state,
                    {
                        kind: 'search',
                        field: 'name',
                        value: null,
                        clear: true,
                        options: { page: 'preserve' },
                    },
                    advancedDefinition(mode, 'exact'),
                    null,
                ),
            ).toThrow();
            expect(() =>
                reduceRequested(
                    state,
                    { kind: 'preset', value: null, clear: true },
                    advancedDefinition(mode),
                    null,
                ),
            ).toThrow();
        },
    );
    it('honors full replacement cursor once, keeps same-size noops and rejects known non-native order', () => {
        const definition = advancedDefinition(),
            state = advancedIntent({ mode: 'cursor', cursor: 'token', per_page: 2 });
        expect(reduceRequested(state, { kind: 'replace', value: state }, definition, null)).toEqual(
            state,
        );
        expect(reduceRequested(state, { kind: 'size', value: 2 }, definition, null)).toEqual(state);
        expect(() =>
            reduceRequested(
                state,
                { kind: 'order', field: 'name', value: { direction: 'asc', sortType: 'natural' } },
                definition,
                null,
            ),
        ).toThrow();
        expect(() =>
            parseRequestedV2({ ...state, order: { kind: 'preset', id: 'featured' } }, definition),
        ).toThrow();
    });
    it('preserves simple distant ordinals only on full replacement, never count clamps', () => {
        const definition = advancedDefinition('simple', 'exact'),
            state = advancedIntent({ mode: 'simple', current: 19, per_page: 2 });
        expect(reduceRequested(state, { kind: 'replace', value: state }, definition, null)).toEqual(
            state,
        );
        expect(
            reduceRequested(
                state,
                { kind: 'search', field: 'name', value: { value: 'Ada', matchType: 'equals' } },
                definition,
                null,
            ).paging,
        ).toEqual({ mode: 'simple', current: 1, per_page: 2 });
    });
});

describe('source paging public handle', () => {
    it('projects honest bootstrap and requires protocol 2 at runtime', async () => {
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const { handle } = create();
        expect(handle.paging).toMatchObject({
            mode: null,
            current: null,
            pages: null,
            counts: null,
            total: null,
            goToPage: null,
            goToLast: null,
            has_next: false,
            per_page: { value: null, options: [] },
        });
        expect(await handle.paging.goToNext()).toMatchObject({ status: 'failure' });
        pending.resolve(respond(advancedPage()));
        await settle();
        expect(handle.paging).toMatchObject({
            mode: 'cursor',
            counts: 'unknown',
            current: null,
            pages: null,
            goToPage: null,
            goToLast: null,
        });
        expect(() =>
            mountTree(() => {
                useDataAdapter('Invalid', JSON.parse('{"paging":"source"}'));
                return () => h('output');
            }),
        ).toThrow('Source paging requires protocol 2');
    });
    it('requires opt-in before accepting an advanced source and preserves offset direct calls', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const offset = mount();
        cleanups.push(offset.unmount);
        await settle();
        expect(offset.handle).toMatchObject({ ready: false, error: { kind: 'protocol' } });
        request.mockResolvedValueOnce(respond(page()));
        const second = mount();
        cleanups.push(second.unmount);
        await settle();
        request.mockResolvedValueOnce(
            respond(page(intent({ paging: { mode: 'offset', current: 2, per_page: 2 } }))),
        );
        expect(await second.handle.paging.goToPage(2)).toEqual({ status: 'success' });
    });
    it('coalesces repeated cursor next, reloads the retained token, and explicitly restarts', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const controls = handle.paging,
            pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const first = controls.goToNext(),
            second = controls.goToNext();
        await settle();
        expect(first).toBe(second);
        expect(request).toHaveBeenCalledTimes(2);
        const target = advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 });
        expect(sent()).toEqual({ version: 2, state: target });
        pending.resolve(
            respond(advancedPage(target, { previous: 'previous-token', next: 'later-token' })),
        );
        expect(await first).toEqual({ status: 'success' });
        request.mockResolvedValueOnce(
            respond(advancedPage(target, { previous: 'previous-token' })),
        );
        await controls.reload();
        expect(sent()).toEqual({ version: 2, state: target });
        request.mockResolvedValueOnce(respond(advancedPage()));
        await controls.goToFirst();
        expect(sent()).toEqual({ version: 2, state: advancedIntent() });
    });
    it('refuses old tokens during pending changed criteria without cancelling or flushing debounce', async () => {
        vi.useFakeTimers();
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create({ searchDebounce: 100 });
        await settle();
        const control = handle.fields?.name?.search;
        if (!control) throw new Error('Missing search');
        const searching = control.call({ value: 'Ada', matchType: 'contains' });
        const requested = handle.requested,
            accepted = handle.data;
        expect(await handle.paging.goToNext()).toMatchObject({ status: 'failure' });
        expect(await handle.global?.order?.call({ id: 'featured' })).toMatchObject({
            status: 'failure',
        });
        expect(
            await control.call({ value: 'Ada', matchType: 'contains' }, { page: 'preserve' }),
        ).toMatchObject({ status: 'failure' });
        expect(handle.requested).toBe(requested);
        expect(handle.data).toBe(accepted);
        expect(request).toHaveBeenCalledTimes(1);
        const next = advancedIntent(undefined, {
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(advancedPage(next)));
        await vi.advanceTimersByTimeAsync(100);
        expect(await searching).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: next });
    });
    it('retains matching rows on expiry and recovers only through explicit first', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const rows = handle.data;
        request.mockResolvedValueOnce(respond(expired, 409));
        expect(await handle.paging.goToNext()).toMatchObject({
            status: 'failure',
            error: { kind: 'cursor_expired', recovery: 'restart' },
        });
        expect(handle.data).toBe(rows);
        expect(handle.ready).toBe(true);
        expect(request).toHaveBeenCalledTimes(2);
        request.mockResolvedValueOnce(respond(expired, 409));
        await handle.paging.reload();
        expect(sent()).toMatchObject({ state: { paging: { cursor: 'next-token' } } });
        request.mockResolvedValueOnce(respond(advancedPage()));
        await handle.paging.goToFirst();
        expect(sent()).toEqual({ version: 2, state: advancedIntent() });
        expect(handle.error).toBeNull();
    });
    it('clears rows and action metadata for invalid cursor and keeps first/reset recovery through reauthorization', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const first = handle.paging.goToFirst;
        const invalid = {
            version: 2,
            error: {
                kind: 'validation',
                code: 'invalid_cursor',
                message: 'The cursor is invalid.',
            },
            errors: { _dapter_kind: ['validation'], _dapter_code: ['invalid_cursor'] },
        };
        request.mockResolvedValueOnce(respond(invalid, 422));
        await handle.paging.goToNext();
        expect(handle).toMatchObject({
            ready: false,
            actions: null,
            fields: null,
            error: { kind: 'validation', code: 'invalid_cursor' },
        });
        expect(handle.paging.mode).toBeNull();
        request.mockResolvedValueOnce(respond(advancedPage()));
        expect(await first()).toEqual({ status: 'success' });
        request.mockResolvedValueOnce(respond(invalid, 422));
        await handle.paging.goToNext();
        request.mockResolvedValueOnce(respond(advancedPage()));
        await handle.resetState();
        expect(sent()).toEqual({ version: 2 });
    });
    it('rejects an acknowledged cursor that differs from the actual submitted token', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        request.mockResolvedValueOnce(
            respond(
                advancedPage(
                    advancedIntent({ mode: 'cursor', cursor: 'other-token', per_page: 2 }),
                ),
            ),
        );
        expect(await handle.paging.goToNext()).toMatchObject({
            status: 'failure',
            error: { kind: 'protocol' },
        });
        expect(handle.ready).toBe(false);
    });
    it('leaves native actions independent and invalidates protected action metadata after invalid cursor', async () => {
        const actions = {
            save: {
                method: 'post',
                transports: ['http'],
                target: null,
                url: 'https://example.test/dapter/actions/Feed/save',
            },
        };
        request.mockResolvedValueOnce(respond({ ...advancedPage(), actions }));
        const { handle } = create();
        await settle();
        const rows = handle.data,
            state = handle.requested;
        request.mockResolvedValueOnce(respond({ saved: true }));
        expect(await handle.actions?.http('save', {})).toMatchObject({
            status: 'completed',
            commit: 'confirmed',
        });
        expect(request).toHaveBeenCalledTimes(2);
        expect(handle.requested).toBe(state);
        expect(handle.data).toBe(rows);
        const saved = handle.actions;
        request.mockResolvedValueOnce(
            respond(
                {
                    version: 2,
                    error: {
                        kind: 'validation',
                        code: 'invalid_cursor',
                        message: 'The cursor is invalid.',
                    },
                    errors: { _dapter_kind: ['validation'], _dapter_code: ['invalid_cursor'] },
                },
                422,
            ),
        );
        await handle.paging.goToNext();
        expect(handle.actions).toBeNull();
        expect(await saved?.http('save', {})).toMatchObject({ status: 'rejected' });
        expect(request).toHaveBeenCalledTimes(3);
    });
    it('keeps source ownership and prevents default offset acquisition from erasing an advanced owner', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        let borrowed: SourceHandle | undefined;
        cleanups.push(
            mountTree(() => {
                const owner = useDataAdapter('Feed', {
                    protocol: 2,
                    paging: 'source',
                    id: 'owned-feed',
                    url: false,
                });
                borrowed = useDataAdapter('Feed', {
                    protocol: 2,
                    paging: 'source',
                    id: 'owned-feed',
                });
                expect(borrowed).toBe(owner);
                expect(() => useDataAdapter('Feed', { id: 'owned-feed' })).toThrow('conflicts');
                return () => h('output');
            }),
        );
        await settle();
        expect(borrowed?.paging.mode).toBe('cursor');
    });
    it('invalidates saved cursor controls and stale responses when the reactive source changes', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle, source } = create();
        await settle();
        const controls = handle.paging,
            old = deferred<HttpResponse>();
        request.mockReturnValueOnce(old.promise);
        const reading = controls.goToNext();
        await settle();
        request.mockResolvedValueOnce(respond(advancedPage()));
        source.value = 'OtherFeed';
        await settle();
        expect(await reading).toEqual({ status: 'superseded' });
        expect(await controls.goToNext()).toEqual({ status: 'superseded' });
        old.resolve(
            respond(
                advancedPage(
                    advancedIntent({ mode: 'cursor', cursor: 'next-token', per_page: 2 }),
                    { data: [{ secret: true }] },
                ),
            ),
        );
        await settle();
        expect(handle.data).toEqual([{ id: 1 }, { id: 2 }]);
        expect(sent()).toEqual({ version: 2 });
    });
    it('exposes simple ordinal navigation with null jumps even when exact counts are available', async () => {
        const initial = advancedIntent({ mode: 'simple', current: 1, per_page: 2 });
        request.mockResolvedValueOnce(respond(advancedPage(initial, { counts: 'exact' })));
        const { handle } = create();
        await settle();
        expect(handle.paging).toMatchObject({
            mode: 'simple',
            counts: 'exact',
            total: 6,
            current: 1,
            pages: null,
            goToPage: null,
            goToLast: null,
        });
        const target = advancedIntent({ mode: 'simple', current: 2, per_page: 2 });
        request.mockResolvedValueOnce(respond(advancedPage(target, { counts: 'exact' })));
        await handle.paging.goToNext();
        expect(sent()).toEqual({ version: 2, state: target });
        request.mockResolvedValueOnce(respond(advancedPage(initial, { counts: 'exact' })));
        await handle.paging.goToPrevious();
        expect(sent()).toEqual({ version: 2, state: initial });
    });
    it('retains source paging across acquisition and bound views and invalidates saved source closures', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        let acquired: SourceHandle | undefined;
        const unmount = mountTree(() => {
            const owner = useDataAdapter('Feed', {
                protocol: 2,
                paging: 'source',
                id: 'bound-feed',
                url: false,
            });
            acquired = useDataAdapter(owner);
            return () =>
                h(
                    DataAdapter<{ adapter: SourceHandle }>,
                    { adapter: owner },
                    { default: () => h('output') },
                );
        });
        cleanups.push(unmount);
        await settle();
        if (!acquired) throw new Error('Missing acquired handle');
        expect(acquired.paging.mode).toBe('cursor');
        const saved = acquired.paging;
        unmount();
        expect(await saved.goToNext()).toEqual({ status: 'superseded' });
    });
});

describe('cursor ownership review', () => {
    it('rejects retained cursor tokens while a size change is pending and saved controls follow the new owner', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const saved = handle.paging;
        const pending = deferred<HttpResponse>();
        request.mockReturnValueOnce(pending.promise);
        const resizing = saved.per_page.setValue(3);
        const resized = advancedIntent({ mode: 'cursor', cursor: null, per_page: 3 });
        expect(handle.requested).toEqual(resized);
        expect(await saved.goToNext()).toMatchObject({ status: 'failure' });
        expect(handle.requested).toEqual(resized);
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        pending.resolve(respond(advancedPage(resized, { next: 'resized-token' })));
        expect(await resizing).toEqual({ status: 'success' });
        const target = advancedIntent({ mode: 'cursor', cursor: 'resized-token', per_page: 3 });
        request.mockResolvedValueOnce(respond(advancedPage(target)));
        expect(await saved.goToNext()).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: target });
    });

    it('clears rows when expiry belongs to changed criteria and First retains that intent', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const search = handle.fields?.name?.search;
        if (!search) throw new Error('Missing search');
        request.mockResolvedValueOnce(respond(expired, 409));
        expect(await search.call({ value: 'Ada', matchType: 'contains' })).toMatchObject({
            status: 'failure',
            error: { kind: 'cursor_expired' },
        });
        expect(handle.ready).toBe(false);
        expect(handle.data).toEqual([]);
        expect(handle.actions).toBeNull();
        const changed = advancedIntent(undefined, {
            fields: { name: { search: { value: 'Ada', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(advancedPage(changed)));
        expect(await handle.paging.goToFirst()).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: changed });
    });

    it('ignores superseded expiry after a newer context succeeds', async () => {
        request.mockResolvedValueOnce(respond(advancedPage()));
        const { handle } = create();
        await settle();
        const old = deferred<HttpResponse>();
        request.mockReturnValueOnce(old.promise);
        const reading = handle.paging.goToNext();
        await settle();
        const changed = advancedIntent(undefined, { global: { search: { value: 'Ada' } } });
        request.mockResolvedValueOnce(respond(advancedPage(changed, { data: [{ id: 9 }] })));
        expect(await handle.global?.search?.call({ value: 'Ada' })).toEqual({ status: 'success' });
        expect(await reading).toEqual({ status: 'superseded' });
        old.resolve(respond(expired, 409));
        await settle();
        expect(handle.ready).toBe(true);
        expect(handle.data).toEqual([{ id: 9 }]);
        expect(handle.error).toBeNull();
        expect(handle.requested).toEqual(changed);
    });
});

it('reports an incompatible advanced cold snapshot instead of leaving an offset owner idle', async () => {
    const url = new URL('https://example.test/catalog?other=kept#results');
    url.searchParams.set(
        'dapter[cold-offset]',
        JSON.stringify({ version: 2, source: 'Products', state: advancedIntent() }),
    );
    vi.stubGlobal('window', Object.assign(new EventTarget(), { location: { href: url.href } }));
    vi.stubGlobal('document', new EventTarget());
    const owner = mount({ id: 'cold-offset', url: true });
    try {
        await settle();
        expect(owner.handle).toMatchObject({
            ready: false,
            status: 'error',
            error: { kind: 'validation', code: 'invalid_state' },
        });
        expect(request).not.toHaveBeenCalled();
        expect(window.location.href).toBe(url.href);
    } finally {
        owner.unmount();
        vi.unstubAllGlobals();
    }
});

it('reports incompatible history restoration before the offset bootstrap settles', async () => {
    const location = { href: 'https://example.test/catalog?other=kept#results' };
    vi.stubGlobal('window', Object.assign(new EventTarget(), { location }));
    vi.stubGlobal('document', new EventTarget());
    const pending = deferred<HttpResponse>();
    request.mockReturnValueOnce(pending.promise);
    const owner = mount({ id: 'restoring-offset', url: true });
    try {
        await settle();
        const url = new URL(location.href);
        url.searchParams.set(
            'dapter[restoring-offset]',
            JSON.stringify({ version: 2, source: 'Products', state: advancedIntent() }),
        );
        location.href = url.href;
        window.dispatchEvent(new Event('popstate'));
        document.dispatchEvent(
            new CustomEvent('inertia:navigate', { detail: { page: { url: url.href } } }),
        );
        await settle();
        expect(owner.handle).toMatchObject({
            ready: false,
            status: 'error',
            error: { kind: 'validation', code: 'invalid_state' },
        });
        expect(request).toHaveBeenCalledTimes(1);
        pending.resolve(respond(page()));
        await settle();
        expect(owner.handle).toMatchObject({ ready: false, status: 'error' });
        expect(window.location.href).toBe(url.href);
    } finally {
        owner.unmount();
        vi.unstubAllGlobals();
    }
});

it.each(['simple', 'cursor'] as const)(
    'restores a complete %s cold snapshot and a later history position with one read each',
    async (mode) => {
        const initial = advancedIntent(
            mode === 'simple'
                ? { mode, current: 7, per_page: 2 }
                : { mode, cursor: 'cold-token', per_page: 2 },
            { global: { search: { value: 'Ada' } } },
        );
        const url = new URL('https://example.test/catalog?other=kept#results');
        url.searchParams.set('dapter[other-reader]', 'unrelated-snapshot');
        url.searchParams.set(
            'dapter[cold-source]',
            JSON.stringify({ version: 2, source: 'Feed', state: initial }),
        );
        const location = { href: url.href };
        vi.stubGlobal('window', Object.assign(new EventTarget(), { location }));
        vi.stubGlobal('document', new EventTarget());
        request.mockResolvedValueOnce(respond(advancedPage(initial)));
        let handle: SourceHandle | undefined;
        const unmount = mountTree(() => {
            handle = useDataAdapter('Feed', {
                protocol: 2,
                paging: 'source',
                id: 'cold-source',
                url: true,
            });
            return () => h('output');
        });
        try {
            await settle();
            expect(handle?.requested).toEqual(initial);
            expect(sent()).toEqual({ version: 2, state: initial });
            expect(request).toHaveBeenCalledTimes(1);
            const restored = advancedIntent(
                mode === 'simple'
                    ? { mode, current: 8, per_page: 2 }
                    : { mode, cursor: 'history-token', per_page: 2 },
                { global: initial.global },
            );
            url.searchParams.set(
                'dapter[cold-source]',
                JSON.stringify({ version: 2, source: 'Feed', state: restored }),
            );
            location.href = url.href;
            request.mockResolvedValueOnce(respond(advancedPage(restored)));
            window.dispatchEvent(new Event('popstate'));
            document.dispatchEvent(
                new CustomEvent('inertia:navigate', { detail: { page: { url: url.href } } }),
            );
            await settle();
            expect(handle?.requested).toEqual(restored);
            expect(sent()).toEqual({ version: 2, state: restored });
            expect(request).toHaveBeenCalledTimes(2);
            expect(window.location.href).toBe(url.href);
            expect(new URL(window.location.href).searchParams.get('other')).toBe('kept');
            expect(new URL(window.location.href).searchParams.get('dapter[other-reader]')).toBe(
                'unrelated-snapshot',
            );
            expect(new URL(window.location.href).hash).toBe('#results');
        } finally {
            unmount();
            vi.unstubAllGlobals();
        }
    },
);
