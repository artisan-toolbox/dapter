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
    useId,
    type ComputedRef,
    type InjectionKey,
    type MaybeRefOrGetter,
} from 'vue';
import {
    createController,
    type DataAdapterHandle,
    type AnyDataAdapterHandle,
    type PagingRequirements,
    type DataAdapterProps,
    type DataAdapterSlot,
    type SourceAdapterProps,
    type ComponentSlot,
} from './adapter.js';

export type DataAdapterOptions = Omit<
    SourceAdapterProps,
    'source' | 'adapter' | 'protocol' | 'paging' | 'echo' | 'polling' | 'infinite'
> &
    (
        | {
              infinite?: SourceAdapterProps['infinite'];
              protocol: 2;
              paging: 'source';
              echo?: SourceAdapterProps['echo'];
              polling?: SourceAdapterProps['polling'];
          }
        | {
              infinite?: SourceAdapterProps['infinite'];
              protocol: 2;
              paging?: 'offset' | undefined;
              echo?: SourceAdapterProps['echo'];
              polling?: SourceAdapterProps['polling'];
          }
        | {
              infinite?: false | undefined;
              protocol?: 1 | undefined;
              paging?: 'offset' | undefined;
              echo?: never;
              polling?: false | undefined;
          }
    );
const optionKeys = [
    'decodeRow',
    'protocol',
    'paging',
    'decodeDefinition',
    'actionDefinitions',
    'searchDebounce',
    'echo',
    'polling',
    'url',
    'scrollContainer',
    'infinite',
] satisfies readonly (keyof DataAdapterOptions)[];
type Entry = {
    input: MaybeRefOrGetter<string>;
    id: string | undefined;
    options: SourceAdapterProps;
    handle: AnyDataAdapterHandle;
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
const handles = new WeakMap<object, Entry>();

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
function facade(state: ComputedRef<AnyDataAdapterHandle>): AnyDataAdapterHandle {
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
    options: Omit<SourceAdapterProps, 'source' | 'adapter'>,
    props?: SourceAdapterProps,
): Entry {
    const ownerProps: SourceAdapterProps = props ?? {
        get source() {
            return toValue(input);
        },
        ...options,
    };
    if (options.id !== undefined && options.id.trim() === '')
        throw new Error('An adapter id must not be empty.');
    const identity = options.id ?? useId();
    const scope = effectScope(true);
    const controller = scope.run(() => createController(ownerProps, identity));
    if (!controller) throw new Error('The adapter owner scope could not be created.');
    const handle = facade(controller.state);
    let disposed = false;
    const entry: Entry = {
        input,
        id: options.id,
        options: ownerProps,
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
function attach(entry: Entry): AnyDataAdapterHandle {
    onMounted(entry.start);
    return entry.handle;
}
function compatible(
    entry: Entry,
    input: MaybeRefOrGetter<string>,
    options: DataAdapterOptions,
): void {
    if (
        (entry.options.paging === 'source' && options.paging !== 'source') ||
        entry.input !== input ||
        optionKeys.some((key) => options[key] !== undefined && entry.options[key] !== options[key])
    )
        throw new Error('An adapter acquisition conflicts with its owner source or decoder.');
}
export function useDataAdapter<H extends AnyDataAdapterHandle>(adapter: H): H;
export function useDataAdapter<O extends DataAdapterOptions>(
    source: string,
    options: O & PagingRequirements<O> & Record<Exclude<keyof O, keyof DataAdapterOptions>, never>,
): DataAdapterSlot<O & { source: string }>;
export function useDataAdapter<O extends DataAdapterOptions & { id: string }>(
    source: MaybeRefOrGetter<string>,
    options: O & PagingRequirements<O> & Record<Exclude<keyof O, keyof DataAdapterOptions>, never>,
): DataAdapterSlot<O & { source: string }>;
export function useDataAdapter<Row>(
    source: string,
    options: DataAdapterOptions & { paging?: 'offset'; decodeRow: (value: unknown) => Row },
): DataAdapterHandle<Row>;
export function useDataAdapter<Row>(
    source: MaybeRefOrGetter<string>,
    options: DataAdapterOptions & {
        paging?: 'offset';
        id: string;
        decodeRow: (value: unknown) => Row;
    },
): DataAdapterHandle<Row>;
export function useDataAdapter(source: string): DataAdapterHandle;
export function useDataAdapter(source: unknown, options: DataAdapterOptions = {}): unknown {
    const local = context();
    if (typeof source === 'object' && source !== null && !isRef(source)) {
        const entry = handles.get(source);
        if (!entry) throw new Error('Unknown adapter handle.');
        return attach(entry);
    }
    if (typeof source !== 'string' && typeof source !== 'function' && !isRef(source))
        throw new Error('Use a Dapter handle or a source string, Ref, or getter.');
    if (typeof source !== 'string' && !options.id)
        throw new Error('A reactive adapter source requires a stable id.');
    if (isSourceGetter(source)) return acquire(source, options, local);
    if (typeof source !== 'string' && !isRef<string>(source)) throw new Error('Invalid source');
    return acquire(source, options, local);
}
function isSourceGetter(source: unknown): source is () => string {
    return typeof source === 'function' && typeof source() === 'string';
}
function acquire(
    source: MaybeRefOrGetter<string>,
    options: DataAdapterOptions,
    local: Context,
): AnyDataAdapterHandle {
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
                    (entry.options.paging === 'source' && options.paging !== 'source') ||
                    (entry.input !== source &&
                        !(typeof source === 'string' && toValue(entry.input) === source)) ||
                    optionKeys.some(
                        (key) => options[key] !== undefined && entry.options[key] !== options[key],
                    )
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

export function componentAdapter<
    P extends DataAdapterProps,
    Row,
    Definition extends import('./protocol-v2.js').AdapterDefinition,
    Actions extends import('./actions.js').ActionDefinitions = import('./adapter.js').ActionsOf<P>,
>(
    props: P &
        DataAdapterProps & {
            decodeRow?: ((value: unknown) => Row) | undefined;
            decodeDefinition?: import('./protocol-v2.js').DefinitionDecoder<Definition> | undefined;
            actionDefinitions?: Actions | undefined;
        },
): ComputedRef<ComponentSlot<P, Row, Definition, Actions> | null>;
export function componentAdapter<P extends DataAdapterProps>(
    props: P,
): ComputedRef<DataAdapterSlot<P> | null>;
export function componentAdapter(
    props: DataAdapterProps,
): ComputedRef<AnyDataAdapterHandle | null> {
    const attached = shallowRef(true);
    const local = context();
    let handle: AnyDataAdapterHandle;
    if (props.adapter !== undefined) {
        if (
            props.source !== undefined ||
            props.id !== undefined ||
            optionKeys.some((key) => props[key] !== undefined)
        )
            throw new Error('A bound adapter cannot override its source, id, or decoder.');
        handle = useDataAdapter(props.adapter);
        local.boundary = handles.get(handle);
        watch(
            () => [props.adapter, props.source, props.id, ...optionKeys.map((key) => props[key])],
            () => {
                if (
                    props.adapter === handle &&
                    props.source === undefined &&
                    props.id === undefined &&
                    optionKeys.every((key) => props[key] === undefined)
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
        const ownerOptions = {
            infinite: props.infinite,
            protocol: props.protocol,
            paging: props.paging,
            searchDebounce: props.searchDebounce,
            echo: props.echo,
            polling: props.polling,
            url: props.url,
        };
        const entry = create(() => props.source, props, props);
        handle = entry.handle;
        local.boundary = entry;
        onMounted(entry.start);
        watch(
            () => [
                props.adapter,
                props.id,
                props.infinite,
                props.protocol,
                props.paging,
                props.searchDebounce,
                props.echo,
                props.polling,
                props.url,
            ],
            () => {
                if (
                    props.adapter === undefined &&
                    props.id === entry.id &&
                    Object.entries(ownerOptions).every(
                        ([key, value]) => Reflect.get(props, key) === value,
                    )
                )
                    return;
                attached.value = false;
                local.boundary = undefined;
                entry.dispose();
                throw new Error(
                    props.adapter !== undefined
                        ? 'An adapter ownership mode cannot change. Remount the view with a stable key.'
                        : props.id !== entry.id
                          ? 'An adapter id cannot change. Remount the view with a stable key.'
                          : 'An adapter creation option cannot change. Remount the view with a stable key.',
                );
            },
            { flush: 'sync' },
        );
    }
    return computed(() => (attached.value ? handle : null));
}
