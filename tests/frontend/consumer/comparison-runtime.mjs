import assert from 'node:assert/strict';
import { createRenderer, nextTick } from 'vue';
import { http } from '@inertiajs/core';
import { useDataAdapter } from '@artisan-toolbox/dapter/dist';

const fullText = {
    engine: 'pgsql',
    configuration: 'pg_catalog.english',
    input: 'plain_text',
    maxBytes: 512,
    ranking: 'filter_only',
};
const locale = {
    locale: 'pt_BR',
    validLocale: 'pt',
    actualLocale: 'root',
    icuVersion: '78.1',
    strength: 'tertiary',
    normalization: true,
    numeric: false,
};
const initial = {
    version: 2,
    fields: {},
    global: {},
    searchMatch: 'all',
    order: { kind: 'default' },
    paging: { mode: 'offset', current: 1, per_page: 2 },
};
const definition = {
    fields: {
        name: {
            search: { valueType: 'string', matchTypes: ['contains', 'full_text'], fullText },
            order: { directions: ['asc'], sortTypes: ['string', 'locale'], locale },
            filters: null,
        },
        status: {
            search: null,
            order: null,
            filters: { selected: { values: [null, false, 0, '0', 'active'], multiple: false } },
        },
    },
    global: {
        multi_order: false,
        multi_order_limit: 1,
        searchMatch: { default: 'all', allowed: ['all'] },
        search: {
            valueType: 'string',
            targets: { name: { valueType: 'string', matchType: 'full_text', fullText } },
        },
        filters: null,
        order: null,
    },
    paging: { mode: 'offset', per_page: 2, per_page_options: [2] },
};
function page(state = initial, capabilities = definition) {
    return {
        version: 2,
        data: [{ id: 1, display_name: 'Resource name', nullable: null }],
        requested: state,
        state: {
            order:
                state.order.kind === 'fields'
                    ? { kind: 'terms', terms: state.order.terms }
                    : { kind: 'source' },
            paging: state.paging,
        },
        paging:
            state.paging.mode === 'cursor'
                ? {
                      ...state.paging,
                      current: null,
                      pages: null,
                      total: null,
                      filtered: null,
                      previous_cursor: null,
                      next_cursor: null,
                      has_previous: false,
                      has_next: false,
                  }
                : {
                      ...state.paging,
                      pages: 1,
                      total: 1,
                      filtered: 1,
                      has_previous: false,
                      has_next: false,
                  },
        capabilities,
        metadata: { records: { mode: 'unidentified' }, channel: null },
    };
}
const wire = (value) => JSON.parse(JSON.stringify(value));
const original = http.getClient();
const previousZiggy = Object.getOwnPropertyDescriptor(globalThis, 'Ziggy');
const sent = [];
let capabilities = definition;
let response = (state) => page(state, capabilities);
http.setClient({
    async request(config) {
        const payload = JSON.parse(config.data);
        sent.push(payload);
        return {
            status: 200,
            headers: {},
            data: JSON.stringify(response(payload.state ?? initial)),
        };
    },
});
Object.defineProperty(globalThis, 'Ziggy', {
    configurable: true,
    value: {
        url: 'https://example.test',
        port: null,
        defaults: {},
        routes: { 'dapter.data': { uri: 'dapter/data/{source}', methods: ['POST'] } },
    },
});
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
        handle = useDataAdapter('Comparisons', {
            protocol: 2,
            paging: 'source',
            id: 'packed-comparisons',
            url: false,
            decodeRow(value) {
                assert.equal(typeof value.display_name, 'string');
                return { resource: { label: value.display_name }, nullable: value.nullable };
            },
        });
        return () => null;
    },
});
try {
    app.mount(node());
    for (let count = 0; count < 20; count++) await Promise.resolve();
    await nextTick();
    assert.equal(handle.ready, true);
    assert.deepEqual(handle.data[0], { resource: { label: 'Resource name' }, nullable: null });
    assert.equal(handle.fields.resource, undefined);
    assert.deepEqual(handle.fields.name.search.available.fullText, fullText);
    assert.deepEqual(handle.fields.name.order.available.locale, locale);
    assert.equal(Object.isFrozen(handle.definition.fields.name.search.fullText), true);
    assert.equal(handle.fields.status.filters.selected.current, null);
    assert.deepEqual(await handle.fields.status.filters.selected.call({ value: null }), {
        status: 'success',
    });
    assert.deepEqual(handle.fields.status.filters.selected.current, { value: null });
    assert.deepEqual(
        await handle.fields.name.search.call({ value: 'é'.repeat(256), matchType: 'full_text' }),
        { status: 'success' },
    );
    assert.deepEqual(
        await handle.fields.name.order.call({ direction: 'asc', sortType: 'locale' }),
        { status: 'success' },
    );
    const restored = wire(handle.requested);
    assert.deepEqual(await handle.replaceState(initial), { status: 'success' });
    assert.deepEqual(await handle.replaceState(restored), { status: 'success' });
    assert.deepEqual(sent.at(-1), { version: 2, state: restored });
    assert.equal(JSON.stringify(sent).includes('configuration'), false);
    assert.equal(JSON.stringify(sent).includes('validLocale'), false);
    const preserved = handle.requested;
    const before = sent.length;
    assert.equal(
        (await handle.fields.name.search.call({ value: 'é'.repeat(257), matchType: 'full_text' }))
            .status,
        'failure',
    );
    assert.equal((await handle.global.search.call({ value: 'a\0b' })).status, 'failure');
    assert.equal(
        (
            await handle.replaceState({
                ...initial,
                fields: { name: { search: { value: 'a', matchType: 'regex' } } },
            })
        ).status,
        'failure',
    );
    assert.equal(sent.length, before);
    assert.equal(handle.requested, preserved);
    await handle.replaceState(initial);
    const oldSearch = handle.fields.name.search;
    const oldOrder = handle.fields.name.order;
    capabilities = {
        ...definition,
        fields: {
            ...definition.fields,
            name: {
                search: { valueType: 'string', matchTypes: ['contains'] },
                order: { directions: ['asc'], sortTypes: ['string'] },
                filters: null,
            },
        },
    };
    await handle.paging.reload();
    const staleBefore = sent.length;
    assert.equal(
        (await oldSearch.call({ value: 'cat', matchType: 'full_text' })).status,
        'failure',
    );
    assert.equal((await oldOrder.call({ direction: 'asc', sortType: 'locale' })).status, 'failure');
    assert.equal(sent.length, staleBefore);
    assert.deepEqual(await oldSearch.call({ value: 'cat', matchType: 'contains' }), {
        status: 'success',
    });
    capabilities = {
        ...definition,
        fields: {
            ...definition.fields,
            name: {
                ...definition.fields.name,
                order: { directions: ['asc'], sortTypes: ['native'] },
            },
        },
        paging: {
            mode: 'cursor',
            per_page: 2,
            per_page_options: [2],
            counts: 'unknown',
            expires_after: 900,
        },
    };
    const cursor = {
        ...initial,
        fields: { name: { search: { value: 'running cats', matchType: 'full_text' } } },
        order: { kind: 'fields', terms: [{ field: 'name', direction: 'asc', sortType: 'native' }] },
        paging: { mode: 'cursor', cursor: null, per_page: 2 },
    };
    response = () => page(cursor, capabilities);
    await handle.resetContext();
    assert.equal(handle.ready, true);
    assert.equal(handle.paging.mode, 'cursor');
    assert.deepEqual(wire(handle.requested), cursor);
    const cursorBefore = sent.length;
    assert.equal(
        (await handle.fields.name.order.call({ direction: 'asc', sortType: 'locale' })).status,
        'failure',
    );
    assert.equal(
        (await oldSearch.call({ value: 'cat', matchType: 'contains' })).status,
        'superseded',
    );
    assert.equal(sent.length, cursorBefore);
    console.log(
        'Verified full-text and locale policies, exact restoration, finite nullable selection, stale controls, and native cursor filtering.',
    );
} finally {
    app.unmount();
    http.setClient(original);
    if (previousZiggy) Object.defineProperty(globalThis, 'Ziggy', previousZiggy);
    else Reflect.deleteProperty(globalThis, 'Ziggy');
}
