import {
    computed,
    effectScope,
    getCurrentInstance,
    inject,
    isRef,
    onBeforeUnmount,
    onMounted,
    provide,
    shallowRef,
    watch,
    toValue,
    type ComputedRef,
    type InjectionKey,
    type MaybeRefOrGetter,
} from 'vue';
import {
    createController,
    type DataAdapterHandle,
    type DataAdapterProps,
    type DataAdapterSlot,
    type SourceAdapterProps,
} from './adapter.js';

export type DataAdapterOptions = {
    id?: string | undefined;
    decodeRow?: ((value: unknown) => unknown) | undefined;
};
type Entry = {
    input: MaybeRefOrGetter<string>;
    id: string | undefined;
    decoder: DataAdapterOptions['decodeRow'];
    handle: DataAdapterHandle;
    start: () => void;
    dispose: () => void;
};
type Context = {
    parent: Context | undefined;
    entries: Map<string, Entry>;
    boundary?: Entry | undefined;
};
const contextKey: InjectionKey<Context> = Symbol('Dapter owner');
const contexts = new WeakMap<object, Context>();
const handles = new WeakMap<DataAdapterHandle, Entry>();

function context(): Context {
    const owner = getCurrentInstance();
    if (!owner) throw new Error('useDataAdapter must be called synchronously during Vue setup.');
    const existing = contexts.get(owner);
    if (existing) return existing;
    const created: Context = { parent: inject(contextKey, undefined), entries: new Map() };
    contexts.set(owner, created);
    provide(contextKey, created);
    onBeforeUnmount(() => contexts.delete(owner));
    return created;
}
function facade(state: ComputedRef<DataAdapterHandle>): DataAdapterHandle {
    return new Proxy(
        { ...state.value },
        {
            get: (_target, key) => Reflect.get(state.value, key),
            set: () => false,
            deleteProperty: () => false,
            defineProperty: () => false,
        },
    );
}
function create(
    input: MaybeRefOrGetter<string>,
    options: DataAdapterOptions,
    props: SourceAdapterProps = {
        get source() {
            return toValue(input);
        },
        decodeRow: options.decodeRow,
    },
): Entry {
    if (options.id !== undefined && options.id.trim() === '')
        throw new Error('An adapter id must not be empty.');
    const scope = effectScope(true);
    const controller = scope.run(() => createController(props));
    if (!controller) throw new Error('The adapter owner scope could not be created.');
    const handle = facade(controller.state);
    let disposed = false;
    const entry: Entry = {
        input,
        id: options.id,
        get decoder() {
            return props.decodeRow;
        },
        handle,
        start() {
            if (!disposed) controller.start();
        },
        dispose() {
            if (disposed) return;
            disposed = true;
            controller.dispose();
            scope.stop();
        },
    };
    handles.set(handle, entry);
    onBeforeUnmount(entry.dispose);
    return entry;
}
function attach(entry: Entry): DataAdapterHandle {
    onMounted(entry.start);
    return entry.handle;
}
function compatible(
    entry: Entry,
    input: MaybeRefOrGetter<string>,
    options: DataAdapterOptions,
): void {
    if (entry.input !== input || (options.decodeRow && entry.decoder !== options.decodeRow))
        throw new Error('An adapter acquisition conflicts with its owner source or decoder.');
}
export function useDataAdapter<Row>(adapter: DataAdapterHandle<Row>): DataAdapterHandle<Row>;
export function useDataAdapter<Row>(
    source: string,
    options: DataAdapterOptions & { decodeRow: (value: unknown) => Row },
): DataAdapterHandle<Row>;
export function useDataAdapter<Row>(
    source: MaybeRefOrGetter<string>,
    options: DataAdapterOptions & { id: string; decodeRow: (value: unknown) => Row },
): DataAdapterHandle<Row>;
export function useDataAdapter(source: string, options?: DataAdapterOptions): DataAdapterHandle;
export function useDataAdapter(
    source: MaybeRefOrGetter<string>,
    options: DataAdapterOptions & { id: string },
): DataAdapterHandle;
export function useDataAdapter(
    source: MaybeRefOrGetter<string> | DataAdapterHandle,
    options: DataAdapterOptions = {},
): DataAdapterHandle {
    const local = context();
    if (typeof source === 'object' && source !== null && !isRef(source)) {
        const entry = handles.get(source);
        if (!entry) throw new Error('Unknown adapter handle.');
        return attach(entry);
    }
    if (typeof source !== 'string' && typeof source !== 'function' && !('value' in source))
        throw new Error('Use a Dapter handle or a source string, Ref, or getter.');
    if (typeof source !== 'string' && !options.id)
        throw new Error('A reactive adapter source requires a stable id.');
    const key = options.id === undefined ? `source:${toValue(source)}` : `id:${options.id}`;
    let current: Context | undefined = local;
    while (current) {
        const boundary = current.boundary;
        const entry =
            current.entries.get(key) ??
            (boundary &&
            (options.id === undefined
                ? toValue(boundary.input) === toValue(source)
                : options.id === boundary.id)
                ? boundary
                : undefined);
        if (entry) {
            if (entry === boundary) {
                if (
                    (entry.input !== source &&
                        !(typeof source === 'string' && toValue(entry.input) === source)) ||
                    (options.decodeRow && entry.decoder !== options.decodeRow)
                )
                    throw new Error('An adapter acquisition conflicts with its owner decoder.');
            } else compatible(entry, source, options);
            return attach(entry);
        }
        current = current.parent;
    }
    const entry = create(source, options);
    local.entries.set(key, entry);
    return attach(entry);
}

export function componentAdapter<P extends DataAdapterProps>(
    props: P,
): ComputedRef<DataAdapterSlot<P> | null>;
export function componentAdapter(props: DataAdapterProps): ComputedRef<DataAdapterHandle | null> {
    const attached = shallowRef(true);
    const local = context();
    let handle: DataAdapterHandle;
    if (props.adapter !== undefined) {
        if (props.source !== undefined || props.decodeRow !== undefined || props.id !== undefined)
            throw new Error('A bound adapter cannot override its source, id, or decoder.');
        handle = useDataAdapter(props.adapter);
        local.boundary = handles.get(handle);
        watch(
            () => [props.adapter, props.source, props.id, props.decodeRow],
            () => {
                if (
                    props.adapter === handle &&
                    props.source === undefined &&
                    props.id === undefined &&
                    props.decodeRow === undefined
                )
                    return;
                attached.value = false;
                local.boundary = undefined;
                throw new Error(
                    'A bound adapter handle cannot change. Remount the view with a stable key.',
                );
            },
            { flush: 'sync' },
        );
    } else {
        const entry = create(() => props.source, props, props);
        handle = entry.handle;
        local.boundary = entry;
        onMounted(entry.start);
        watch(
            () => [props.adapter, props.id],
            () => {
                if (props.adapter === undefined && props.id === entry.id) return;
                attached.value = false;
                local.boundary = undefined;
                entry.dispose();
                throw new Error(
                    props.adapter !== undefined
                        ? 'An adapter ownership mode cannot change. Remount the view with a stable key.'
                        : 'An adapter id cannot change. Remount the view with a stable key.',
                );
            },
            { flush: 'sync' },
        );
    }
    return computed(() => (attached.value ? handle : null));
}
