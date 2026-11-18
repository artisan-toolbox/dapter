import assert from 'node:assert/strict';
import { createRenderer, nextTick } from 'vue';
import { http } from '@inertiajs/core';
import { useDataAdapter, defineActions } from '@artisan-toolbox/dapter/dist';
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json' with { type: 'json' };
const response = corpus.successes[0]?.response;
assert.ok(response);
let handle;
let requests = 0;
let mutations = 0;
let actionResponse = async () => ({ status: 200, data: '{"saved":true}', headers: {} });
const original = http.getClient();
http.setClient({
    async request(config) {
        requests++;
        if (config.url.includes('/data/')) {
            return {
                status: 200,
                headers: {},
                data: JSON.stringify({
                    ...response,
                    actions: {
                        save: {
                            method: 'post',
                            transports: ['http'],
                            target: null,
                            url: 'https://example.test/actions/save',
                        },
                    },
                }),
            };
        }
        mutations++;
        assert.equal(config.headers['X-Dapter-Action'], '1');
        assert.equal(config.headers['X-Dapter-Transport'], 'http');
        return actionResponse(config);
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
const app = renderer.createApp({
    setup() {
        handle = useDataAdapter('Products', {
            actionDefinitions: defineActions({ save: { transports: ['http'] } }),
        });
        return () => null;
    },
});
try {
    app.mount(node());
    for (let count = 0; count < 15; count++) await Promise.resolve();
    await nextTick();
    assert.equal(handle.ready, true);
    assert.ok(handle.actions);
    let success = 0;
    const completed = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onSuccess(data) {
                assert.deepEqual(data, { saved: true });
                success++;
            },
        },
    );
    assert.equal(completed.status, 'completed');
    assert.equal(success, 1);
    let validation = 0;
    let finish = 0;
    actionResponse = async () => ({
        status: 422,
        data: '{"errors":{"name":["Changed before validation"]}}',
        headers: { 'content-type': 'application/json' },
    });
    const uncertain = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onError(errors) {
                assert.deepEqual(errors, { name: ['Changed before validation'] });
                validation++;
            },
            onFinish() {
                finish++;
            },
        },
    );
    assert.deepEqual(uncertain, {
        status: 'validation',
        commit: 'unknown',
        errors: { name: ['Changed before validation'] },
    });
    assert.equal(validation, 1);
    assert.equal(finish, 1);
    assert.equal(mutations, 2);
    const callbackFailure = new Error('Validation callback failed');
    const beforeCallback = requests;
    const callbackOutcome = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onError() {
                throw callbackFailure;
            },
        },
    );
    assert.deepEqual(callbackOutcome, {
        status: 'failed',
        commit: 'unknown',
        error: callbackFailure,
    });
    assert.equal(requests, beforeCallback + 1);
    const before = requests;
    const rejected = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onBefore: () => false,
            onFinish() {
                finish++;
            },
        },
    );
    assert.equal(rejected.status, 'rejected');
    assert.equal(rejected.commit, 'not_started');
    assert.equal(requests, before);
    assert.equal(finish, 1);
    let cancelled = 0;
    actionResponse = async (config) => {
        assert.equal(config.signal.aborted, true);
        throw new DOMException('Aborted', 'AbortError');
    };
    const cancellation = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onCancelToken: (token) => token.cancel(),
            onCancel() {
                cancelled++;
            },
        },
    );
    assert.deepEqual(cancellation, { status: 'cancelled', commit: 'unknown' });
    assert.equal(cancelled, 1);
    let network = 0;
    actionResponse = async () => ({ status: 200, data: '{', headers: {} });
    const malformed = await handle.actions.http(
        'save',
        { id: 1 },
        {
            onNetworkError() {
                network++;
            },
        },
    );
    assert.equal(malformed.status, 'failed');
    assert.equal(malformed.commit, 'unknown');
    assert.equal(network, 1);
    actionResponse = async () => ({
        status: 422,
        data: '{',
        headers: { 'content-type': 'application/json' },
    });
    const malformedValidation = await handle.actions.http('save', { id: 1 });
    assert.ok(['failed', 'validation'].includes(malformedValidation.status));
    assert.equal(malformedValidation.commit, 'unknown');
    assert.equal(handle.actions.pending, 0);
    assert.equal(handle.status, 'success');
    assert.equal(handle.error, null);
    assert.deepEqual(handle.requested, response.requested);
    process.stdout.write(
        'Verified native success, mutate-then-422, callback failures, onBefore rejection, cancellation, malformed JSON, and independent read state.',
    );
} finally {
    app.unmount();
    http.setClient(original);
    delete globalThis.Ziggy;
}
