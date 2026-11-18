import assert from 'node:assert/strict';
import { createRenderer, nextTick } from 'vue';
import { http } from '@inertiajs/core';
import { useDataAdapter } from '@artisan-toolbox/dapter/dist';

const initial = {
    version: 2,
    fields: {},
    global: {},
    searchMatch: 'all',
    order: { kind: 'default' },
    paging: { mode: 'cursor', cursor: null, per_page: 2 },
};
function page(state = initial, next = 'next-token') {
    return {
        version: 2,
        data: [{ id: 1 }, { id: 2 }],
        requested: state,
        state: { order: { kind: 'source' }, paging: state.paging },
        paging: {
            ...state.paging,
            current: null,
            pages: null,
            total: null,
            filtered: null,
            previous_cursor: null,
            next_cursor: next,
            has_previous: false,
            has_next: next !== null,
        },
        capabilities: {
            fields: {},
            global: {
                multi_order: false,
                multi_order_limit: 1,
                searchMatch: { default: 'all', allowed: ['all'] },
                search: null,
                filters: null,
                order: null,
            },
            paging: {
                mode: 'cursor',
                per_page: 2,
                per_page_options: [2, 3],
                counts: 'unknown',
                expires_after: 900,
            },
        },
        metadata: { records: { mode: 'unidentified' }, channel: null },
        actions: {
            save: {
                method: 'post',
                transports: ['http'],
                target: null,
                url: 'https://example.test/actions/save',
            },
        },
    };
}
const expired = {
    version: 2,
    error: {
        kind: 'cursor_expired',
        code: 'cursor_expired',
        message: 'The cursor has expired. Restart pagination to continue.',
        recovery: 'restart',
    },
};
const respond = (data, status = 200) => ({
    status,
    headers: { 'content-type': 'application/json' },
    data: JSON.stringify(data),
});
const original = http.getClient();
const sent = [];
let response = () => respond(page());
http.setClient({
    async request(config) {
        sent.push(JSON.parse(config.data));
        return response();
    },
});
globalThis.Ziggy = {
    url: 'https://example.test',
    port: null,
    defaults: {},
    routes: { 'dapter.data': { uri: 'data/{source}', methods: ['POST'] } },
};
const node = () => ({ parent: null, children: [] });
const renderer = createRenderer({
    createElement: node,
    createText: node,
    createComment: node,
    insert(child, parent) {
        child.parent = parent;
        parent.children.push(child);
    },
    remove(child) {
        if (child.parent)
            child.parent.children = child.parent.children.filter((item) => item !== child);
    },
    parentNode: (target) => target.parent,
    nextSibling: () => null,
    patchProp() {},
    setText() {},
    setElementText() {},
});
let handle;
const app = renderer.createApp({
    setup() {
        handle = useDataAdapter('Feed', {
            protocol: 2,
            paging: 'source',
            id: 'packed-infinite',
            infinite: true,
            url: false,
        });
        return () => null;
    },
});
try {
    app.mount(node());
    for (let count = 0; count < 30; count++) await Promise.resolve();
    await nextTick();
    assert.equal(handle.ready, true);
    assert.equal(handle.infinite.status, 'idle');
    assert.equal(handle.data.length, 2);
    response = () =>
        respond(
            page(
                { ...initial, paging: { ...initial.paging, cursor: 'next-token' } },
                'third-token',
            ),
        );
    const append = handle.infinite.append();
    assert.equal(handle.infinite.append(), append);
    assert.deepEqual(await append, { status: 'success' });
    assert.equal(handle.data.length, 4);
    assert.equal(sent.at(-1).state.paging.cursor, 'next-token');
    response = () => respond(expired, 409);
    assert.equal((await handle.infinite.append()).status, 'failure');
    assert.equal(handle.infinite.status, 'paused');
    assert.equal(handle.data.length, 4);
    const requests = sent.length;
    assert.equal((await handle.infinite.append()).status, 'superseded');
    assert.equal(sent.length, requests);
    response = () => respond(page());
    assert.deepEqual(await handle.infinite.retry(), { status: 'success' });
    assert.equal(sent.at(-1).state.paging.cursor, null);
    assert.equal(handle.data.length, 2);
    response = () =>
        respond(page({ ...initial, paging: { ...initial.paging, cursor: 'next-token' } }, null));
    await handle.infinite.append();
    assert.equal(handle.data.length, 4);
    assert.equal(handle.infinite.status, 'exhausted');
    response = () => respond(page());
    await handle.paging.reload();
    for (let count = 0; count < 30; count++) await Promise.resolve();
    assert.equal(sent.at(-1).state.paging.cursor, null);
    assert.equal(handle.data.length, 2);
    const captured = handle.infinite;
    app.unmount();
    assert.deepEqual(await captured.append(), { status: 'superseded' });
    assert.deepEqual(await captured.retry(), { status: 'superseded' });
    process.stdout.write(
        'Verified packed infinite accumulation, native expiry pause, retry restart, exhaustion, first-window reload, and disposal.',
    );
} finally {
    app.unmount();
    http.setClient(original);
    delete globalThis.Ziggy;
}
