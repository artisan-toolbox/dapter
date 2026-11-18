import { createRenderer, defineComponent, h, nextTick, reactive } from 'vue';
import {
    DataAdapter,
    useDataAdapter,
    type AdapterDefinition,
    type DataAdapterHandle,
    type DataAdapterOptions,
    type RequestedStateV2,
} from '@artisan-toolbox/dapter';

type Host = { parent: Host | null; children: Host[] };
const node = (): Host => ({ parent: null, children: [] });
const renderer = createRenderer<Host, Host>({
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
    setText() {},
    setElementText() {},
    patchProp() {},
    parentNode: (target) => target.parent,
    nextSibling: (target) =>
        target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
});
export const definition = {
    fields: {
        name: {
            search: { matchTypes: ['contains', 'equals'], valueType: 'string' },
            order: { directions: ['asc', 'desc'], sortTypes: ['natural', 'string'] },
            filters: null,
        },
        amount: {
            search: { matchTypes: ['equals'], valueType: 'number' },
            order: null,
            filters: null,
        },
        status: {
            search: null,
            order: null,
            filters: {
                selected: { values: [null, false, 0, '0', 'active'], multiple: false },
                tags: { values: ['a', 'b'], multiple: true, empty: 'clear' },
            },
        },
    },
    global: {
        multi_order: false,
        multi_order_limit: 1,
        searchMatch: { default: 'all', allowed: ['all'] },
        search: null,
        filters: null,
        order: null,
    },
    paging: { mode: 'offset', per_page: 2, per_page_options: [2, 3] },
} satisfies AdapterDefinition;
export function intent(patch: Partial<RequestedStateV2> = {}): RequestedStateV2 {
    return {
        version: 2,
        fields: {},
        global: {},
        searchMatch: 'all',
        order: { kind: 'default' },
        paging: { mode: 'offset', current: 1, per_page: 2 },
        ...patch,
    };
}
export function page(
    state = intent(),
    capabilities: AdapterDefinition = definition,
    marker = 'Product',
) {
    const { current, per_page } = state.paging,
        total = 6;
    return {
        version: 2,
        data: Array.from(
            { length: Math.min(per_page, total - (current - 1) * per_page) },
            (_, index) => ({ id: (current - 1) * per_page + index + 1, name: marker }),
        ),
        requested: state,
        state: {
            order:
                state.order.kind === 'default'
                    ? { kind: 'source' }
                    : state.order.kind === 'fields'
                      ? { kind: 'terms', terms: state.order.terms }
                      : {
                            kind: 'preset',
                            id: state.order.id,
                            identity: `preset:${state.order.id}`,
                            opaque: true,
                        },
            paging: state.paging,
        },
        paging: {
            ...state.paging,
            pages: Math.ceil(total / per_page),
            total,
            filtered: total,
            has_previous: current > 1,
            has_next: current < Math.ceil(total / per_page),
        },
        capabilities,
        metadata: { records: { mode: 'unidentified' }, channel: null },
    };
}
export async function settle() {
    for (let count = 0; count < 12; count++) await Promise.resolve();
    await nextTick();
}
export function mount(
    options: Extract<DataAdapterOptions, { paging?: 'offset' | undefined }> = {},
    setup?: (handle: DataAdapterHandle, source: { value: string }) => void,
) {
    const source = reactive({ value: 'Products' });
    let handle: DataAdapterHandle | undefined;
    const app = renderer.createApp(
        defineComponent({
            setup() {
                handle = useDataAdapter(() => source.value, {
                    protocol: 2,
                    url: false,
                    ...options,
                    id: options.id ?? 'phase-two',
                });
                setup?.(handle, source);
                const bound = handle;
                return () =>
                    h(
                        DataAdapter<{ adapter: DataAdapterHandle }>,
                        { adapter: bound },
                        { default: () => h('output') },
                    );
            },
        }),
    );
    app.mount(node());
    if (!handle) throw new Error('Missing public handle');
    let mounted = true;
    return {
        handle,
        source,
        unmount: () => {
            if (mounted) {
                mounted = false;
                app.unmount();
            }
        },
    };
}
export function deferred<T>() {
    let resolve: (value: T) => void = () => {
        throw new Error('Uninitialized deferred');
    };
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
export function mountTree(setup: () => () => import('vue').VNode) {
    const app = renderer.createApp(defineComponent({ setup }));
    app.mount(node());
    return () => app.unmount();
}
