import assert from 'node:assert/strict';
import { createRenderer, h, nextTick, watch } from 'vue';
import { http } from '@inertiajs/core';
import { DataAdapter, useDataAdapter } from '@artisan-toolbox/dapter/dist';

const channel = { type: 'private', name: 'products.reader', event: '.dapter.updated' };
const initial = {
    version: 2,
    fields: {},
    global: {},
    searchMatch: 'all',
    order: { kind: 'default' },
    paging: { mode: 'offset', current: 1, per_page: 2 },
};
function page(state = initial) {
    return {
        version: 2,
        data: ['first', 'second'],
        requested: state,
        state: { order: { kind: 'source' }, paging: state.paging },
        paging: {
            ...state.paging,
            pages: 1,
            total: 2,
            filtered: 2,
            has_previous: false,
            has_next: false,
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
            paging: { mode: 'offset', per_page: 2, per_page_options: [2] },
        },
        metadata: {
            records: {
                mode: 'revisioned',
                format: 'uint64-decimal',
                rows: [
                    { id: 1, revision: '1' },
                    { id: '1', revision: '1' },
                ],
            },
            channel,
        },
    };
}
const respond = (data) => ({ status: 200, data: JSON.stringify(data), headers: {} });
const sent = [];
let response = () => respond(page());
const original = http.getClient();
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
const updates = new Set(),
    errors = new Set(),
    stopped = [];
const socket = {
    listen(_event, callback) {
        updates.add(callback);
    },
    stopListening(_event, callback) {
        stopped.push(callback);
        updates.delete(callback);
    },
    error(callback) {
        errors.add((value) => callback(value));
    },
};
const echo = { private: () => socket, channel: () => socket };
let sequence = 0;
const event = (data, id = '1', revision = '18446744073709551615') => ({
    version: 1,
    eventId: `00000000-0000-0000-0000-${String(++sequence).padStart(12, '0')}`,
    source: 'Products',
    channel,
    data: [data],
    records: {
        mode: 'revisioned',
        format: 'uint64-decimal',
        rows: [{ id, revision }],
    },
});
const emit = (data, id, revision) => {
    [...updates].forEach((callback) => callback(event(data, id, revision)));
};
const node = () => ({ parent: null, children: [] });
const renderer = createRenderer({
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
    parentNode: (target) => target.parent,
    nextSibling: (target) =>
        target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    patchProp() {},
    setText() {},
    setElementText() {},
});
let handle, bound;
const app = renderer.createApp({
    setup() {
        handle = useDataAdapter('Products', {
            protocol: 2,
            echo,
            polling: { interval: 30 },
            url: false,
            decodeRow(value) {
                if (typeof value !== 'string') throw new Error('Invalid projected row');
                return { label: value };
            },
        });
        watch(
            () => handle.data[0]?.label,
            (label) => {
                if (label === 'numeric-buffered') emit('during-render', 1, '3');
            },
            { flush: 'post' },
        );
        return () =>
            h(
                DataAdapter,
                { adapter: handle },
                {
                    default(adapter) {
                        bound = adapter;
                        return h('output', adapter.ready ? adapter.data[0].label : adapter.status);
                    },
                },
            );
    },
});
async function settle() {
    for (let count = 0; count < 20; count++) await Promise.resolve();
    await nextTick();
}
async function until(condition) {
    for (let count = 0; count < 100; count++) {
        if (condition()) return;
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
    throw new Error('Packed live condition timed out');
}
try {
    app.mount(node());
    await settle();
    assert.equal(handle.ready, true);
    assert.equal(bound.data, handle.data);
    assert.equal(updates.size, 1);
    assert.equal(errors.size, 1);
    const snapshot = JSON.stringify(handle.paging),
        requested = handle.requested;
    emit('replaced');
    await settle();
    assert.deepEqual(handle.data, [{ label: 'first' }, { label: 'replaced' }]);
    assert.equal(bound.data, handle.data);
    assert.equal(JSON.stringify(handle.paging), snapshot);
    assert.equal(handle.requested, requested);
    assert.equal(sent.length, 1);
    let resolveRead;
    response = () =>
        new Promise((resolve) => {
            resolveRead = resolve;
        });
    const reload = handle.paging.reload();
    await settle();
    emit('buffered');
    emit('numeric-buffered', 1, '2');
    await new Promise((resolve) => setTimeout(resolve, 65));
    assert.equal(sent.length, 2);
    assert.equal(handle.data[1].label, 'replaced');
    resolveRead(respond(page()));
    await reload;
    await settle();
    assert.equal(handle.data[1].label, 'buffered');
    assert.equal(handle.data[0].label, 'during-render');
    response = () => respond(page());
    await until(() => sent.length === 3);
    await settle();
    assert.deepEqual(sent[2], { version: 2, state: initial });
    const detached = [...updates][0];
    for (const error of errors) error(new Error('Denied'));
    assert.equal(handle.integrationError.code, 'subscription_failed');
    assert.equal(updates.size, 0);
    await handle.paging.reload();
    await settle();
    assert.equal(updates.size, 1);
    assert.equal(handle.integrationError, null);
    detached(event('detached'));
    assert.equal(handle.data[1].label, 'second');
    const oldReset = handle.resetContext,
        oldListener = [...updates][0];
    const reset = handle.resetContext();
    assert.deepEqual(handle.data, []);
    assert.equal(updates.size, 0);
    await reset;
    await settle();
    assert.deepEqual(sent.at(-1), { version: 2 });
    assert.equal((await oldReset()).status, 'superseded');
    oldListener(event('stale'));
    assert.equal(handle.data[1].label, 'second');
    app.unmount();
    assert.equal(updates.size, 0);
    assert.equal(
        stopped.every((callback) => typeof callback === 'function'),
        true,
    );
    const count = sent.length;
    await new Promise((resolve) => setTimeout(resolve, 65));
    assert.equal(sent.length, count);
    process.stdout.write(
        'Verified packed real Vue bound ownership, typed scalar Resource replacement, uint64 revisions, reentrant buffered render replay, polling exclusion, recovered subscription callback rejection, explicit reset, stale context callback rejection, and disposal.',
    );
} finally {
    app.unmount();
    http.setClient(original);
    delete globalThis.Ziggy;
}
