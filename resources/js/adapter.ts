import type { DapterEcho, PollingOptions, IntegrationError } from './realtime.js';
import { createActionController, type ActionDefinitions, type ActionControls } from './actions.js';
import { HttpNetworkError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, shallowRef, watch, type ComputedRef, type MaybeRefOrGetter } from 'vue';
import {
    freeze,
    intent,
    parseFailure,
    parsePage,
    protocolError,
    validationFailure,
    type AcceptedState,
    type AdapterError,
    type Page,
    type ReadonlyDeep,
    type RequestedState,
} from './protocol.js';
import { createV2Controller } from './controller-v2.js';
import type {
    AdapterDefinition,
    DefinitionDecoder,
    FieldDefinition,
    FilterSelection,
    RequestedStateV2,
    AcceptedStateV2,
    AdapterMetadata,
    SearchDefinition,
    OrderDefinition,
    FilterDefinition,
} from './protocol-v2.js';
import type { StateOptions } from './requested-state.js';
export type { AdapterError, ReadonlyDeep } from './protocol.js';
export type InfiniteOptions =
    | false
    | true
    | {
          readonly root: MaybeRefOrGetter<HTMLElement | null>;
          readonly sentinel: MaybeRefOrGetter<HTMLElement | null>;
          readonly axis?: 'y' | 'x';
      };
export type InfiniteControls = {
    readonly status: 'idle' | 'loading' | 'paused' | 'exhausted';
    readonly append: () => Promise<AsyncOutcome>;
    readonly retry: () => Promise<AsyncOutcome>;
};
export type PagingSelection = 'offset' | 'source';
type SourceAdapterBase = {
    source: string;
    id?: string | undefined;
    decodeRow?: ((value: unknown) => unknown) | undefined;
    decodeDefinition?: DefinitionDecoder | undefined;
    actionDefinitions?: ActionDefinitions | undefined;
    searchDebounce?: number | undefined;
    echo?: DapterEcho | undefined;
    polling?: PollingOptions | undefined;
    url?: boolean | undefined;
    scrollContainer?: MaybeRefOrGetter<HTMLElement | null> | undefined;
    infinite?: InfiniteOptions | undefined;
    adapter?: never;
};
export type SourceAdapterProps = SourceAdapterBase & {
    protocol?: 1 | 2 | undefined;
    paging?: PagingSelection | undefined;
};
export type PagingRequirements<P> = (P extends { paging: 'source' } ? { protocol: 2 } : unknown) &
    (P extends { echo: DapterEcho } | { polling: { readonly interval: number } }
        ? { protocol: 2 }
        : unknown) &
    (P extends { infinite: infer Infinite }
        ? [Infinite] extends [false | undefined]
            ? unknown
            : { protocol: 2 }
        : unknown);
export type AnyDataAdapterHandle = DataAdapterHandle<
    unknown,
    AdapterDefinition,
    ActionDefinitions,
    PagingSelection
>;
export type DataAdapterProps =
    | (SourceAdapterProps & ({ protocol: 2; paging: 'source' } | { paging?: 'offset' | undefined }))
    | {
          adapter: AnyDataAdapterHandle;
          source?: never;
          id?: never;
          decodeRow?: never;
          protocol?: never;
          paging?: never;
          decodeDefinition?: never;
          actionDefinitions?: never;
          searchDebounce?: never;
          echo?: never;
          polling?: never;
          url?: never;
          scrollContainer?: never;
          infinite?: never;
      };
export type ComponentOwnerProps<P> = P extends { adapter: AnyDataAdapterHandle }
    ? Extract<DataAdapterProps, { adapter: AnyDataAdapterHandle }>
    : P extends { paging: 'source' }
      ? SourceAdapterProps & { paging: 'source'; protocol: 2 }
      : SourceAdapterProps & { paging?: 'offset' | undefined };
export type RowOf<P> = P extends {
    adapter: DataAdapterHandle<infer Row, infer _Definition, infer _Actions, infer _Paging>;
}
    ? Row
    : P extends { decodeRow: (value: unknown) => infer Row }
      ? Row
      : unknown;
export type AsyncOutcome =
    | { readonly status: 'success' }
    | { readonly status: 'superseded' }
    | { readonly status: 'failure'; readonly error: AdapterError };
export type OffsetPaging = {
    readonly mode: 'offset';
    readonly loading: boolean;
    readonly empty: boolean;
    readonly current: number | null;
    readonly pages: number | null;
    readonly total: number | null;
    readonly filtered: number | null;
    readonly has_previous: boolean;
    readonly has_next: boolean;
    readonly per_page: {
        readonly value: number | null;
        readonly options: readonly number[];
        readonly setValue: (value: number) => Promise<AsyncOutcome>;
    };
    readonly goToFirst: () => Promise<AsyncOutcome>;
    readonly goToPrevious: () => Promise<AsyncOutcome>;
    readonly goToNext: () => Promise<AsyncOutcome>;
    readonly goToPage: (value: number) => Promise<AsyncOutcome>;
    readonly goToLast: () => Promise<AsyncOutcome>;
    readonly reload: () => Promise<AsyncOutcome>;
};
type PagingControls = Pick<
    OffsetPaging,
    | 'loading'
    | 'empty'
    | 'has_previous'
    | 'has_next'
    | 'per_page'
    | 'goToFirst'
    | 'goToPrevious'
    | 'goToNext'
    | 'reload'
>;
type TraversalControls = Omit<PagingControls, 'per_page'> & {
    readonly per_page: Omit<OffsetPaging['per_page'], 'value'> & { readonly value: number };
};
type CountView =
    | { readonly counts: 'unknown'; readonly total: null; readonly filtered: null }
    | { readonly counts: 'exact'; readonly total: number; readonly filtered: number };
export type SimplePaging = TraversalControls &
    CountView & {
        readonly mode: 'simple';
        readonly current: number;
        readonly pages: null;
        readonly previous_cursor: null;
        readonly next_cursor: null;
        readonly goToPage: null;
        readonly goToLast: null;
    };
export type CursorPaging = TraversalControls &
    CountView & {
        readonly mode: 'cursor';
        readonly current: null;
        readonly pages: null;
        readonly previous_cursor: string | null;
        readonly next_cursor: string | null;
        readonly goToPage: null;
        readonly goToLast: null;
    };
export type UnreadyPaging = Omit<
    PagingControls,
    'empty' | 'has_previous' | 'has_next' | 'per_page'
> & {
    readonly empty: false;
    readonly has_previous: false;
    readonly has_next: false;
    readonly per_page: {
        readonly value: null;
        readonly options: readonly [];
        readonly setValue: OffsetPaging['per_page']['setValue'];
    };
    readonly mode: null;
    readonly current: null;
    readonly pages: null;
    readonly counts: null;
    readonly total: null;
    readonly filtered: null;
    readonly previous_cursor: null;
    readonly next_cursor: null;
    readonly goToPage: null;
    readonly goToLast: null;
};
export type SourcePaging = UnreadyPaging | OffsetPaging | SimplePaging | CursorPaging;
export type PagingOf<P> = P extends { adapter: { paging: OffsetPaging } }
    ? 'offset'
    : P extends { adapter: AnyDataAdapterHandle }
      ? 'source'
      : [P] extends [{ paging: 'source' }]
        ? 'source'
        : 'offset';
export type DefinitionOf<P> = P extends {
    adapter: DataAdapterHandle<infer _Row, infer Definition, infer _Actions, infer _Paging>;
}
    ? Definition
    : P extends {
            decodeDefinition: (
                value: ReadonlyDeep<AdapterDefinition>,
            ) => infer Definition extends AdapterDefinition;
        }
      ? Definition
      : AdapterDefinition;
export type ActionsOf<P> = P extends {
    adapter: DataAdapterHandle<infer _Row, infer _Definition, infer A, infer _Paging>;
}
    ? A
    : P extends { actionDefinitions: infer A extends ActionDefinitions }
      ? A
      : ActionDefinitions;
export type DataAdapterSlot<P = SourceAdapterProps> = DataAdapterHandle<
    RowOf<P>,
    DefinitionOf<P>,
    ActionsOf<P>,
    PagingOf<P>
>;
export type FieldCapability<Available, Current, Input> = Readonly<{
    readonly available: Available;
    readonly current: Current | null;
    call(value: Input, options?: StateOptions): Promise<AsyncOutcome>;
    clear(options?: StateOptions): Promise<AsyncOutcome>;
}>;
type SearchValue<T> = T extends 'string' ? string : T extends 'integer' | 'number' ? number : never;
export type SearchInput<S extends SearchDefinition> = {
    readonly value: SearchValue<S['valueType']>;
    readonly matchType: S['matchTypes'][number];
};
export type FilterInput<F extends FilterDefinition> = {
    readonly value: F extends { readonly multiple: true }
        ? F extends { readonly empty: 'reject' }
            ? readonly [F['values'][number], ...F['values'][number][]]
            : readonly F['values'][number][]
        : F['values'][number];
};
export type OrderInput<O extends OrderDefinition, Multi extends boolean = false> = {
    readonly direction: O['directions'][number];
    readonly sortType: O['sortTypes'][number];
    readonly multiSort?: Multi;
};
type SearchCapability<S> = S extends SearchDefinition
    ? FieldCapability<S, SearchInput<S>, SearchInput<S>>
    : null;
type OrderCapability<O, Multi extends boolean> = O extends OrderDefinition
    ? FieldCapability<
          O,
          Omit<OrderInput<O, Multi>, 'multiSort'> & { readonly priority: number },
          OrderInput<O, Multi>
      >
    : null;
type FilterCapabilities<F> =
    F extends Readonly<Record<string, FilterDefinition | undefined>>
        ? {
              readonly [K in keyof F]?: F[K] extends FilterDefinition
                  ? FieldCapability<F[K], FilterInput<F[K]>, FilterInput<F[K]>>
                  : FieldCapability<NonNullable<F[K]>, FilterSelection, FilterSelection>;
          }
        : null;
export type FieldView<
    F extends FieldDefinition = FieldDefinition,
    Multi extends boolean = boolean,
> = {
    readonly search: SearchCapability<F['search']>;
    readonly order: OrderCapability<F['order'], Multi>;
    readonly filters: FilterCapabilities<F['filters']>;
};
export type FieldsView<D extends AdapterDefinition = AdapterDefinition> = {
    readonly [K in keyof D['fields']]?: FieldView<
        NonNullable<D['fields'][K]>,
        D['global']['multi_order']
    >;
};
export type GlobalView<D extends AdapterDefinition = AdapterDefinition> = Omit<
    D['global'],
    'search' | 'filters' | 'order'
> & {
    readonly search: D['global']['search'] extends null
        ? null
        :
              | FieldCapability<
                    NonNullable<D['global']['search']>,
                    { readonly value: string },
                    { readonly value: string; readonly targets?: never; readonly matchType?: never }
                >
              | (null extends D['global']['search'] ? null : never);
    readonly filters: FilterCapabilities<D['global']['filters']>;
    readonly order: D['global']['order'] extends null
        ? null
        :
              | FieldCapability<
                    NonNullable<D['global']['order']>,
                    { readonly id: NonNullable<D['global']['order']>['presets'][number]['id'] },
                    {
                        readonly id: NonNullable<D['global']['order']>['presets'][number]['id'];
                        readonly direction?: never;
                        readonly sortType?: never;
                        readonly multiSort?: never;
                    }
                >
              | (null extends D['global']['order'] ? null : never);
};
export type DataAdapterHandle<
    Row = unknown,
    Definition extends AdapterDefinition = AdapterDefinition,
    Actions extends ActionDefinitions = ActionDefinitions,
    Paging extends PagingSelection = 'offset',
> = {
    readonly status: 'idle' | 'loading' | 'success' | 'error';
    readonly error: AdapterError | null;
    readonly integrationError: IntegrationError | null;
    readonly resetContext: () => Promise<AsyncOutcome>;
    readonly restartPolling: () => void;
    readonly infinite: InfiniteControls | null;
    readonly requested:
        | RequestedState
        | (Paging extends 'source'
              ? import('./protocol-v2.js').SourceRequestedStateV2
              : RequestedStateV2)
        | null;
    readonly accepted:
        | AcceptedState
        | AcceptedStateV2<
              Paging extends 'source'
                  ? import('./protocol-v2.js').PagingPosition
                  : RequestedStateV2['paging']
          >
        | null;
    readonly definition: ReadonlyDeep<Definition> | null;
    readonly capabilities: ReadonlyDeep<Definition> | null;
    readonly fields: FieldsView<Definition> | null;
    readonly metadata: AdapterMetadata | null;
    readonly global: GlobalView<Definition> | null;
    readonly setSearchMatch:
        | {
              call(
                  value: Definition['global']['searchMatch']['allowed'][number],
                  options?: StateOptions,
              ): Promise<AsyncOutcome>;
          }['call']
        | null;
    readonly actions: ActionControls<Actions> | null;
    readonly replaceState: (value: unknown) => Promise<AsyncOutcome>;
    readonly resetState: () => Promise<AsyncOutcome>;
    readonly paging: Paging extends 'source' ? SourcePaging : OffsetPaging;
} & (
    | { readonly ready: false; readonly data: readonly [] }
    | { readonly ready: true; readonly data: readonly ReadonlyDeep<Row>[] }
);
function decode<P extends SourceAdapterProps>(
    data: readonly unknown[],
    props: P,
): readonly ReadonlyDeep<RowOf<P>>[];
function decode(data: readonly unknown[], props: SourceAdapterProps): readonly unknown[] {
    return freeze(data.map((row) => (props.decodeRow ? props.decodeRow(row) : row)));
}
type Accepted<P extends SourceAdapterProps> = {
    page: Page;
    data: readonly ReadonlyDeep<RowOf<P>>[];
};
function createLegacyController<P extends SourceAdapterProps>(
    props: P,
): { state: ComputedRef<DataAdapterSlot<P>>; start: () => void; dispose: () => void };
function createLegacyController(props: SourceAdapterProps): {
    state: ComputedRef<AnyDataAdapterHandle>;
    start: () => void;
    dispose: () => void;
} {
    const accepted = shallowRef<Accepted<SourceAdapterProps> | null>(null);
    const requested = shallowRef<RequestedState | null>(null);
    const error = shallowRef<AdapterError | null>(null);
    const status = shallowRef<AnyDataAdapterHandle['status']>('idle');
    let generation = 0,
        epoch = 0;
    let mounted = false;
    let pending: { key: string; cancel: () => void; promise: Promise<AsyncOutcome> } | null = null;
    const actions = createActionController({
        metadata: () => accepted.value?.page.actions ?? null,
        definitions: () => props.actionDefinitions,
        active: () => mounted,
        epoch: () => epoch,
    });
    const success = (): Promise<AsyncOutcome> => Promise.resolve({ status: 'success' });
    function rejectLocal(): Promise<AsyncOutcome> {
        return Promise.resolve({
            status: 'failure',
            error: {
                kind: 'validation',
                code: 'invalid_paging',
                message: 'Choose an available page and page size.',
            },
        });
    }
    function invalidate(): void {
        generation++;
        const previous = pending;
        pending = null;
        previous?.cancel();
    }
    function submit(state: RequestedState | null, force = false): Promise<AsyncOutcome> {
        if (!mounted) return Promise.resolve({ status: 'superseded' });
        const key = JSON.stringify(state);
        if (!force && pending?.key === key) return pending.promise;
        if (
            !force &&
            !pending &&
            status.value === 'success' &&
            JSON.stringify(requested.value) === key
        )
            return success();
        invalidate();
        const active = generation;
        requested.value = freeze(state);
        status.value = 'loading';
        error.value = null;
        const form = useHttp<{ version: number; state?: RequestedState }, unknown>(
            state ? { version: 1, state } : { version: 1 },
        ).withAllErrors();
        let rejected: AdapterError | null = null;
        let received = false;
        const promise: Promise<AsyncOutcome> = Promise.resolve().then(async () => {
            if (active !== generation || !mounted) return { status: 'superseded' };
            try {
                const response = await form.post(route('dapter.data', { source: props.source }), {
                    onError: (errors: unknown) => {
                        rejected = validationFailure(errors);
                    },
                    onHttpException: (response: {
                        readonly data: string;
                        readonly status: number;
                    }) => {
                        try {
                            rejected = parseFailure(JSON.parse(response.data));
                        } catch {
                            rejected = protocolError();
                        }
                        if (!rejected) rejected = protocolError();
                        if ([401, 403, 404].includes(response.status))
                            rejected = {
                                kind: 'access',
                                code: 'access_denied',
                                message: 'Access to this data source was denied.',
                            };
                    },
                });
                if (active !== generation || !mounted) return { status: 'superseded' };
                if (rejected) throw rejected;
                received = true;
                const page = parsePage(response);
                const data = decode(page.data, props);
                if (active !== generation || !mounted) return { status: 'superseded' };
                accepted.value = { page, data };
                requested.value = page.requested;
                status.value = 'success';
                error.value = null;
                return { status: 'success' };
            } catch (cause) {
                if (active !== generation || !mounted) return { status: 'superseded' };
                const failure =
                    rejected ??
                    (!received && cause instanceof HttpNetworkError
                        ? ({
                              kind: 'network',
                              code: 'request_failed',
                              message: 'The data source could not be reached.',
                          } satisfies AdapterError)
                        : protocolError());
                if (failure.kind === 'access' || failure.kind === 'protocol') accepted.value = null;
                error.value = Object.freeze(failure);
                status.value = 'error';
                return { status: 'failure', error: failure };
            } finally {
                if (active === generation) pending = null;
            }
        });
        pending = { key, cancel: () => form.cancel(), promise };
        return promise;
    }
    function goToPage(value: number): Promise<AsyncOutcome> {
        const page = accepted.value?.page;
        if (!page || !Number.isSafeInteger(value) || value < 1 || value > page.paging.pages)
            return rejectLocal();
        return submit(intent(value, requested.value?.paging.per_page ?? page.paging.per_page));
    }
    function setValue(value: number): Promise<AsyncOutcome> {
        const page = accepted.value?.page;
        if (!page || !page.options.includes(value)) return rejectLocal();
        if ((requested.value?.paging.per_page ?? page.paging.per_page) === value)
            return submit(requested.value ?? page.requested);
        return submit(intent(1, value));
    }
    const reload = (): Promise<AsyncOutcome> => submit(requested.value, true);
    const goToFirst = (): Promise<AsyncOutcome> => (accepted.value ? goToPage(1) : success());
    const goToPrevious = (): Promise<AsyncOutcome> =>
        (requested.value?.paging.current ?? 1) > 1
            ? goToPage((requested.value?.paging.current ?? 1) - 1)
            : success();
    const goToNext = (): Promise<AsyncOutcome> =>
        accepted.value && (requested.value?.paging.current ?? 1) < accepted.value.page.paging.pages
            ? goToPage((requested.value?.paging.current ?? 1) + 1)
            : success();
    const goToLast = (): Promise<AsyncOutcome> =>
        accepted.value ? goToPage(accepted.value.page.paging.pages) : success();
    const slot = computed<AnyDataAdapterHandle>(() => {
        const result = accepted.value;
        const captured = epoch;
        const own =
            <Args extends unknown[]>(command: (...args: Args) => Promise<AsyncOutcome>) =>
            (...args: Args): Promise<AsyncOutcome> =>
                mounted && captured === epoch
                    ? command(...args)
                    : Promise.resolve({ status: 'superseded' });
        const paging: OffsetPaging = {
            mode: 'offset',
            loading: status.value === 'loading',
            empty: !!result && result.page.data.length === 0,
            current: result?.page.paging.current ?? null,
            pages: result?.page.paging.pages ?? null,
            total: result?.page.paging.total ?? null,
            filtered: result?.page.paging.filtered ?? null,
            has_previous: result?.page.paging.has_previous ?? false,
            has_next: result?.page.paging.has_next ?? false,
            per_page: {
                value: result?.page.paging.per_page ?? null,
                options: result?.page.options ?? Object.freeze([]),
                setValue: own(setValue),
            },
            goToPage: own(goToPage),
            goToFirst: own(goToFirst),
            goToPrevious: own(goToPrevious),
            goToNext: own(goToNext),
            goToLast: own(goToLast),
            reload: own(reload),
        };
        Object.freeze(paging.per_page);
        Object.freeze(paging);
        const common = {
            status: status.value,
            error: error.value,
            integrationError: null,
            infinite: null,
            resetContext: own(resetContext),
            restartPolling: () => {},
            requested: requested.value,
            accepted: result?.page.state ?? null,
            paging,
            definition: null,
            capabilities: null,
            metadata: null,
            fields: null,
            global: null,
            setSearchMatch: null,
            actions: actions.controls(),
            replaceState: (_value: unknown) => rejectLocal(),
            resetState: own(() => submit(null, true)),
        };
        return result
            ? Object.freeze({ ...common, ready: true, data: result.data })
            : Object.freeze({ ...common, ready: false, data: Object.freeze<[]>([]) });
    });
    function resetContext(): Promise<AsyncOutcome> {
        epoch++;
        actions.reset();
        invalidate();
        accepted.value = null;
        requested.value = null;
        error.value = null;
        status.value = 'idle';
        return mounted ? submit(null, true) : Promise.resolve({ status: 'superseded' });
    }
    watch(
        () => [props.source, props.decodeRow, props.actionDefinitions],
        () => {
            epoch++;
            actions.reset();
            invalidate();
            accepted.value = null;
            requested.value = null;
            error.value = null;
            status.value = 'idle';
            if (mounted) void submit(null);
        },
        { flush: 'sync' },
    );
    return {
        state: slot,
        start() {
            if (mounted) return;
            mounted = true;
            void submit(null);
        },
        dispose() {
            mounted = false;
            epoch++;
            actions.reset();
            invalidate();
            accepted.value = null;
            requested.value = null;
            error.value = null;
            status.value = 'idle';
        },
    };
}

export function createController<P extends SourceAdapterProps>(
    props: P,
    identity: string,
): { state: ComputedRef<DataAdapterSlot<P>>; start: () => void; dispose: () => void } {
    if (
        props.protocol !== 2 &&
        (props.echo !== undefined || (props.polling !== undefined && props.polling !== false))
    )
        throw new Error('Echo and polling require protocol 2.');
    if (props.infinite !== undefined && props.infinite !== false && props.protocol !== 2)
        throw new Error('Infinite loading requires protocol 2.');
    if (props.infinite !== undefined && typeof props.infinite !== 'boolean') {
        const option = props.infinite;
        if (
            !option ||
            typeof option !== 'object' ||
            !('root' in option) ||
            !('sentinel' in option) ||
            Object.keys(option).some((key) => !['root', 'sentinel', 'axis'].includes(key)) ||
            (option.axis !== undefined && option.axis !== 'y' && option.axis !== 'x')
        )
            throw new Error('Infinite observation requires root, sentinel, and an x or y axis.');
    }
    if (props.paging !== undefined && props.paging !== 'offset' && props.paging !== 'source')
        throw new Error('Choose offset or source paging.');
    if (props.paging === 'source' && props.protocol !== 2)
        throw new Error('Source paging requires protocol 2.');
    return props.protocol === 2
        ? createV2Controller(props, identity)
        : createLegacyController(props);
}

export type ComponentSlot<
    P extends DataAdapterProps,
    Row,
    Definition extends AdapterDefinition,
    Actions extends ActionDefinitions = ActionsOf<P>,
> = [P] extends [{ adapter: AnyDataAdapterHandle }]
    ? DataAdapterSlot<P>
    : DataAdapterHandle<Row, Definition, Actions, PagingOf<P>>;

export type ComponentDecoderRequirements<
    P extends DataAdapterProps,
    Row,
    Definition extends AdapterDefinition,
    Actions extends ActionDefinitions = ActionsOf<P>,
> = [P] extends [{ adapter: AnyDataAdapterHandle }]
    ? unknown
    : PagingRequirements<Omit<P, 'infinite'>> &
          (P extends { infinite: unknown }
              ? { infinite?: false | undefined } | { protocol: 2 }
              : unknown) &
          (unknown extends Row ? unknown : { decodeRow: (value: unknown) => Row }) &
          (AdapterDefinition extends Definition
              ? unknown
              : { decodeDefinition: DefinitionDecoder<Definition> }) &
          (ActionDefinitions extends Actions ? unknown : { actionDefinitions: Actions });
