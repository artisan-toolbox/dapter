import { http, router, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import {
    DataAdapter,
    useDataAdapter,
    type DataAdapterHandle,
    type RequestedStateV2,
} from '@artisan-toolbox/dapter';
import { h, ref } from 'vue';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { browserAt, browserState, shareValue } from './browser-fixtures.js';
import { deferred, definition, intent, mount, mountTree, page, settle } from './phase2-fixtures.js';

const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const clean = 'https://example.test/products?category=shoes#results';
const key = 'catalog.browse';
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const search = 'ação 🧵 東京';
const searched = intent({ fields: { name: { search: { value: search, matchType: 'contains' } } } });
const urlWith = (value: string, name = key) => {
    const url = new URL(clean);
    url.searchParams.set(name, value);
    return url.href;
};
function valueAt(href: string, name = key) {
    return new URL(href).searchParams.get(name);
}
const respond = (data: unknown, status = 200): HttpResponse => ({
    status,
    headers: {},
    data: JSON.stringify(data),
});
function server(policy = browserState) {
    request.mockImplementation(async (config) => {
        const { state }: { state?: RequestedStateV2 } = JSON.parse(String(config.data));
        const expected = config.headers?.['X-Dapter-State-Version'];
        return respond({
            ...page(expected && expected !== policy.version ? undefined : state),
            browserState: policy,
        });
    });
}
function owner(id = 'browse') {
    const mounted = mount({ id, url: true });
    cleanups.push(mounted.unmount);
    return mounted;
}
async function drain() {
    await settle();
    await settle();
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
    server();
});
afterEach(() => {
    for (const cleanup of cleanups.splice(0)) cleanup();
    http.setClient(original);
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('publishes immutable local references and shares only the accepted Unicode criteria', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    const created = new Date('2026-01-01T10:00:00Z');
    vi.setSystemTime(created);
    const { location, storage, navigate } = browserAt(clean);
    const first = owner();
    expect(first.handle.share()).toBeNull();
    await drain();
    expect(first.handle.share()).toBe(clean);
    expect(request.mock.calls[0]?.[0].headers).toHaveProperty('X-Dapter-Browser-State', '1');
    await first.handle.replaceState(searched);
    await drain();
    const searchedUrl = location.href;
    const id = valueAt(searchedUrl);
    expect(id).toMatch(uuid);
    const stored = storage.getItem('dapter:state:' + id);
    expect(JSON.parse(stored ?? 'null')).toEqual({
        source: 'Products',
        version: browserState.version,
        state: searched,
        expiresAt: created.getTime() + 240 * 60_000,
    });
    const portable = first.handle.share();
    expect(portable).toBe(urlWith(shareValue('Products', searched)));
    expect(location.href).toBe(searchedUrl);
    vi.setSystemTime(created.getTime() + 60_000);
    await first.handle.paging.reload();
    await drain();
    expect(location.href).toBe(searchedUrl);
    expect(storage.getItem('dapter:state:' + id)).toBe(stored);
    await first.handle.paging.goToNext();
    await drain();
    const pagedUrl = location.href;
    const pageId = valueAt(pagedUrl);
    expect(pageId).toMatch(uuid);
    expect(pageId).not.toBe(id);
    expect(storage.length).toBe(2);
    navigate(searchedUrl);
    await drain();
    expect(first.handle.requested).toEqual(searched);
    expect(location.href).toBe(searchedUrl);
    navigate(pagedUrl);
    await drain();
    expect(first.handle.paging.current).toBe(2);
    expect(storage.length).toBe(2);
    await first.handle.resetState();
    await drain();
    expect(location.href).toBe(clean);
    expect(storage.getItem('dapter:state:' + pageId)).toBeNull();
    expect(storage.getItem('dapter:state:' + id)).toBe(stored);
    first.unmount();
    expect(first.handle.share()).toBeNull();
    location.href = searchedUrl;
    const second = owner();
    const count = request.mock.calls.length;
    await drain();
    expect(request).toHaveBeenCalledTimes(count + 2);
    expect(second.handle.requested).toEqual(searched);
    expect(storage.getItem('dapter:state:' + id)).toBe(stored);
    second.unmount();
    storage.clear();
    location.href = portable!;
    const recipient = owner();
    await drain();
    const imported = valueAt(location.href);
    expect(imported).toMatch(uuid);
    expect(imported).not.toBe(id);
    expect(recipient.handle.requested).toEqual(searched);
    expect(JSON.parse(storage.getItem('dapter:state:' + imported) ?? 'null').expiresAt).toBe(
        Date.now() + 240 * 60_000,
    );
    await recipient.handle.fields?.name?.search?.clear();
    await drain();
    expect(location.href).toBe(clean);
    expect(storage.length).toBe(0);
});

it.each([
    'missing',
    'malformed',
    'wrong source',
    'wrong version',
    'expired',
    'removed field',
    'expired store',
    'default',
    'old JSON',
])('discards a %s reference before requesting any saved criteria', async (kind) => {
    const id = crypto.randomUUID();
    const { location, storage } = browserAt(urlWith(id));
    let policy = { ...browserState, expiration: { at: Date.now() + 1_000 } };
    const record = {
        source: 'Products',
        version: browserState.version,
        state: searched,
        expiresAt: Date.now() + 60_000,
    };
    if (kind === 'wrong source') record.source = 'Customers';
    if (kind === 'wrong version') record.version = 'b'.repeat(64);
    if (kind === 'expired') record.expiresAt = Date.now();
    if (kind === 'expired store') policy = { ...policy, expiration: { at: Date.now() } };
    if (kind === 'default') record.state = intent();
    if (kind === 'removed field')
        record.state = intent({
            fields: { removed: { search: { value: 'x', matchType: 'contains' } } },
        });
    if (kind !== 'missing') storage.setItem('dapter:state:' + id, JSON.stringify(record));
    if (kind === 'malformed') location.href = urlWith('s1.!');
    if (kind === 'old JSON')
        location.href = urlWith(
            JSON.stringify({ source: 'Products', version: 2, state: searched }),
        );
    storage.setItem('preferences', 'blue');
    request.mockResolvedValueOnce(respond({ ...page(), browserState: policy }));
    const { handle } = owner();
    await drain();
    expect(handle.requested).toEqual(intent());
    expect(request).toHaveBeenCalledTimes(1);
    expect(JSON.parse(String(request.mock.calls[0]?.[0].data))).toEqual({ version: 2 });
    expect(location.href).toBe(clean);
    expect(storage.getItem('preferences')).toBe('blue');
    if (kind === 'wrong source') expect(storage.getItem('dapter:state:' + id)).not.toBeNull();
    if (['wrong version', 'expired', 'default', 'expired store'].includes(kind))
        expect(storage.getItem('dapter:state:' + id)).toBeNull();
});

it('prunes all obsolete versions of this source and restarts on a changed policy without replaying stale criteria', async () => {
    const { storage, location } = browserAt(clean);
    const { handle } = owner();
    await drain();
    await handle.replaceState(searched);
    await drain();
    await handle.paging.goToPage(2);
    await drain();
    const other = 'dapter:state:' + crypto.randomUUID();
    storage.setItem(
        other,
        JSON.stringify({
            source: 'Other',
            version: browserState.version,
            state: searched,
            expiresAt: Date.now() + 60_000,
        }),
    );
    server({ ...browserState, version: 'b'.repeat(64) });
    await handle.paging.reload();
    await drain();
    expect(request.mock.calls.at(-1)?.[0].headers?.['X-Dapter-State-Version']).toBe(
        browserState.version,
    );
    expect(handle.requested).toEqual(intent());
    expect(storage.length).toBe(1);
    expect(storage.key(0)).toBe(other);
    expect(location.href).toBe(clean);
});

it('uses configured grouping and size to recognize a return to source defaults', async () => {
    const custom = {
        ...definition,
        paging: { ...definition.paging, per_page: 3 },
        global: { ...definition.global, searchMatch: { default: 'any', allowed: ['all', 'any'] } },
    } as const;
    const defaults = intent({
        searchMatch: 'any',
        paging: { mode: 'offset', current: 1, per_page: 3 },
    });
    request.mockImplementation(async (config) =>
        respond({
            ...page(JSON.parse(String(config.data)).state ?? defaults, custom),
            browserState,
        }),
    );
    const { location, storage } = browserAt(clean);
    const { handle } = owner();
    await drain();
    await handle.paging.per_page.setValue(2);
    await drain();
    expect(valueAt(location.href)).toMatch(uuid);
    await handle.setSearchMatch?.('all');
    await drain();
    await handle.paging.per_page.setValue(3);
    await drain();
    const lastId = valueAt(location.href);
    expect(lastId).toMatch(uuid);
    await handle.setSearchMatch?.('any');
    await drain();
    expect(location.href).toBe(clean);
    expect(storage.getItem('dapter:state:' + lastId)).toBeNull();
});

it('keeps accepted rows and portable sharing when local storage is disabled', async () => {
    const { location, storage } = browserAt(urlWith(shareValue('Products', searched)));
    vi.spyOn(storage, 'setItem').mockImplementation(() => {
        throw new Error('Quota exceeded');
    });
    const { handle } = owner();
    await drain();
    expect(handle.requested).toEqual(searched);
    expect(handle.ready).toBe(true);
    expect(location.href).toBe(clean);
    expect(handle.share()).toBe(urlWith(shareValue('Products', searched)));
    await handle.paging.goToNext();
    await drain();
    expect(handle.paging.current).toBe(2);
    expect(location.href).toBe(clean);
});

it('keeps sibling views independent and removes their keys only from the exported link', async () => {
    const { location } = browserAt(clean);
    const left = owner('left');
    const right = owner('right');
    await drain();
    await left.handle.replaceState(searched);
    await right.handle.paging.goToPage(2);
    await drain();
    expect(valueAt(location.href, 'catalog.left')).toMatch(uuid);
    expect(valueAt(location.href, 'catalog.right')).toMatch(uuid);
    expect(left.handle.share()).toBe(urlWith(shareValue('Products', searched), 'catalog.left'));
    expect(valueAt(location.href, 'catalog.right')).toMatch(uuid);
});

it('never flashes bootstrap rows while a restore is pending or applies a response after reset', async () => {
    const { location, storage } = browserAt(urlWith(shareValue('Products', searched)));
    const pending = deferred<HttpResponse>();
    request
        .mockResolvedValueOnce(respond({ ...page(), browserState }))
        .mockReturnValueOnce(pending.promise);
    const { handle } = owner();
    await drain();
    expect(request).toHaveBeenCalledTimes(2);
    expect(handle.ready).toBe(false);
    expect(handle.data).toEqual([]);
    expect(handle.share()).toBeNull();
    await handle.resetState();
    pending.resolve(respond({ ...page(searched), browserState }));
    await drain();
    expect(handle.requested).toEqual(intent());
    expect(location.href).toBe(clean);
    expect(storage.length).toBe(0);
});

it('keeps old servers and disabled owners usable without publishing browser references', async () => {
    const { location, storage } = browserAt(clean);
    request.mockImplementation(async (config) =>
        respond(page(JSON.parse(String(config.data)).state)),
    );
    const old = owner();
    await drain();
    await old.handle.replaceState(searched);
    await drain();
    expect(old.handle.ready).toBe(true);
    expect(old.handle.share()).toBeNull();
    old.unmount();
    server();
    const disabled = mount({ url: false });
    cleanups.push(disabled.unmount);
    await drain();
    await disabled.handle.replaceState(searched);
    await drain();
    expect(disabled.handle.share()).toBeNull();
    expect(request.mock.calls.at(-1)?.[0].headers).not.toHaveProperty('X-Dapter-Browser-State');
    expect(location.href).toBe(clean);
    expect(storage.length).toBe(0);
});

it('exposes synchronous sharing from a source component, its slot, and a bound component', async () => {
    const { location } = browserAt(clean);
    const sourceRef = ref<{ share: () => string | null } | null>(null);
    const boundRef = ref<{ share: () => string | null } | null>(null);
    let slot: DataAdapterHandle | undefined;
    const release = mountTree(() => {
        const borrowed = useDataAdapter('Secondary', { protocol: 2, id: 'secondary' });
        return () =>
            h('div', [
                h(
                    DataAdapter<{ source: string; protocol: 2 }>,
                    { source: 'Products', protocol: 2, ref: sourceRef },
                    {
                        default: (handle: DataAdapterHandle) => {
                            slot = handle;
                            return h('output');
                        },
                    },
                ),
                h(
                    DataAdapter<{ adapter: DataAdapterHandle }>,
                    { adapter: borrowed, ref: boundRef },
                    { default: () => h('output') },
                ),
            ]);
    });
    cleanups.push(release);
    await drain();
    expect(sourceRef.value?.share()).toBe(clean);
    expect(boundRef.value?.share()).toBe(clean);
    await slot?.replaceState(searched);
    await drain();
    expect(valueAt(location.href, 'catalog')).toMatch(uuid);
    expect(sourceRef.value?.share()).toBe(slot?.share());
    expect(valueAt(sourceRef.value!.share()!, 'catalog')).toBe(shareValue('Products', searched));
    expect(boundRef.value?.share()).toBe(clean);
});

it('removes uncommitted records if a delayed Inertia navigation loses to a newer read', async () => {
    const { location, storage } = browserAt(clean);
    const { handle } = owner();
    await drain();
    const written = deferred<void>();
    const delayed = vi.spyOn(router, 'replace').mockImplementationOnce((options) => {
        void written.promise.then(() => {
            location.href = String(options.url);
            const finish = Reflect.get(options, 'onFinish');
            if (typeof finish === 'function') Reflect.apply(finish, undefined, []);
        });
    });
    await handle.replaceState(searched);
    await drain();
    expect(delayed).toHaveBeenCalled();
    await handle.paging.goToPage(2);
    written.resolve();
    await drain();
    const id = valueAt(location.href);
    expect(id).toMatch(uuid);
    expect(storage.length).toBe(1);
    expect(JSON.parse(storage.getItem('dapter:state:' + id) ?? 'null').state.paging.current).toBe(
        2,
    );
});

it('uses the absolute deadline and never renews a snapshot when the same view reloads at expiry', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    const at = Date.now() + 45_000;
    request.mockImplementation(async (config) =>
        respond({
            ...page(JSON.parse(String(config.data)).state),
            browserState: { ...browserState, expiration: { at } },
        }),
    );
    const { location, storage } = browserAt(clean);
    const { handle } = owner();
    await drain();
    await handle.replaceState(searched);
    await drain();
    const id = valueAt(location.href);
    expect(JSON.parse(storage.getItem('dapter:state:' + id) ?? 'null').expiresAt).toBe(at);
    vi.setSystemTime(at);
    await handle.paging.reload();
    await drain();
    expect(handle.ready).toBe(true);
    expect(handle.requested).toEqual(searched);
    expect(location.href).toBe(clean);
    expect(storage.length).toBe(0);
});

it('shares the infinite window start and reuses its UUID when more rows append', async () => {
    const { location, storage } = browserAt(clean);
    const { handle, unmount } = mount({ id: 'browse', protocol: 2, url: true, infinite: true });
    cleanups.push(unmount);
    await drain();
    await handle.replaceState(searched);
    await drain();
    const startUrl = location.href;
    expect(await handle.infinite?.append()).toEqual({ status: 'success' });
    await drain();
    expect(handle.paging.current).toBe(2);
    expect(handle.data).toHaveLength(4);
    expect(location.href).toBe(startUrl);
    expect(storage.length).toBe(1);
    expect(handle.share()).toBe(urlWith(shareValue('Products', searched)));
});

it('keeps the accepted share intact when a read fails', async () => {
    const { location, storage } = browserAt(clean);
    const { handle } = owner();
    await drain();
    await handle.replaceState(searched);
    await drain();
    const accepted = location.href;
    request.mockResolvedValueOnce(
        respond({ errors: { _dapter_kind: ['validation'], state: ['Rejected.'] } }, 422),
    );
    expect(await handle.paging.goToNext()).toMatchObject({ status: 'failure' });
    await drain();
    expect(handle.share()).toBe(urlWith(shareValue('Products', searched)));
    expect(location.href).toBe(accepted);
    expect(storage.length).toBe(1);
});

it('honors a reset requested during cold negotiation without applying the portable candidate', async () => {
    const { location } = browserAt(urlWith(shareValue('Products', searched)));
    const pending = deferred<HttpResponse>();
    request.mockReturnValueOnce(pending.promise);
    const { handle } = owner();
    await drain();
    const reset = handle.resetState();
    pending.resolve(respond({ ...page(), browserState }));
    await reset;
    await drain();
    expect(handle.requested).toEqual(intent());
    expect(request).toHaveBeenCalledTimes(1);
    expect(location.href).toBe(clean);
});

it('reports duplicate effective keys and leaves the first owner operational', async () => {
    const { location } = browserAt(clean);
    const first = owner();
    await drain();
    const duplicate = owner();
    await drain();
    expect(duplicate.handle.error).toMatchObject({
        kind: 'validation',
        code: 'duplicate_state_key',
    });
    expect(duplicate.handle.share()).toBeNull();
    await first.handle.replaceState(searched);
    await drain();
    expect(valueAt(location.href)).toMatch(uuid);
});

it('restores defaults on Back after deployment removes a field, before the server evaluates saved criteria', async () => {
    const { location, storage, navigate } = browserAt(clean);
    const { handle } = owner();
    await drain();
    await handle.replaceState(searched);
    await drain();
    const historical = location.href;
    await handle.paging.goToNext();
    await drain();
    const count = request.mock.calls.length;
    const revised = { ...browserState, version: 'b'.repeat(64) };
    const fields = { ...definition.fields, name: { ...definition.fields.name, search: null } };
    request.mockImplementationOnce(async (config) => {
        if (config.headers?.['X-Dapter-State-Version'] !== browserState.version)
            return respond({ errors: { _dapter_kind: ['validation'] } }, 422);
        return respond({ ...page(intent(), { ...definition, fields }), browserState: revised });
    });
    navigate(historical);
    await drain();
    expect(request).toHaveBeenCalledTimes(count + 1);
    expect(handle.ready).toBe(true);
    expect(handle.fields?.name?.search).toBeNull();
    expect(handle.requested).toEqual(intent());
    expect(location.href).toBe(clean);
    expect(storage.length).toBe(0);
});
