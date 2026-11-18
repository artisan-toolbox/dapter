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
const invalid = {
    version: 2,
    error: { kind: 'validation', code: 'invalid_cursor', message: 'The cursor is invalid.' },
    errors: { _dapter_kind: ['validation'], _dapter_code: ['invalid_cursor'] },
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
            id: 'packed-feed',
            url: false,
        });
        return () => null;
    },
});
try {
    app.mount(node());
    assert.equal(handle.paging.mode, null);
    assert.equal(handle.actions, null);
    for (let count = 0; count < 20; count++) await Promise.resolve();
    await nextTick();
    assert.equal(handle.ready, true);
    assert.equal(handle.paging.mode, 'cursor');
    assert.equal(handle.paging.counts, 'unknown');
    assert.equal(handle.paging.total, null);
    assert.equal(handle.paging.goToLast, null);
    assert.equal(handle.paging.goToPage, null);
    const rows = handle.data;
    const savedActions = handle.actions;
    const savedFirst = handle.paging.goToFirst;
    response = () => respond(expired, 409);
    const first = handle.paging.goToNext();
    const repeated = handle.paging.goToNext();
    assert.equal(first, repeated);
    assert.deepEqual(await first, { status: 'failure', error: expired.error });
    assert.equal(sent.length, 2);
    assert.equal(sent.at(-1).state.paging.cursor, 'next-token');
    assert.equal(handle.data, rows);
    assert.equal(handle.ready, true);
    assert.equal(handle.error.recovery, 'restart');
    await handle.paging.reload();
    assert.equal(sent.at(-1).state.paging.cursor, 'next-token');
    assert.equal(sent.length, 3);
    response = () => respond(invalid, 422);
    assert.deepEqual(await handle.paging.reload(), {
        status: 'failure',
        error: {
            kind: 'validation',
            code: 'invalid_cursor',
            message: 'The data source rejected the request.',
        },
    });
    assert.equal(handle.ready, false);
    assert.deepEqual(handle.data, []);
    assert.equal(handle.actions, null);
    assert.equal(handle.paging.mode, null);
    assert.equal((await savedActions.http('save', {})).status, 'rejected');
    assert.equal(sent.length, 4);
    response = () => respond(page());
    assert.deepEqual(await savedFirst(), { status: 'success' });
    assert.deepEqual(sent.at(-1), { version: 2, state: initial });
    assert.equal(handle.ready, true);
    await handle.resetState();
    assert.deepEqual(sent.at(-1), { version: 2 });
    const controls = handle.paging;
    app.unmount();
    assert.deepEqual(await controls.goToNext(), { status: 'superseded' });
    assert.equal(sent.length, 6);
    process.stdout.write(
        'Verified packed cursor bootstrap, coalescing, native 409 expiry, native 422 invalid-cursor clearing, explicit recovery, action metadata, and disposal.',
    );
} finally {
    app.unmount();
    http.setClient(original);
    delete globalThis.Ziggy;
}
