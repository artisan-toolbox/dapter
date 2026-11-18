import { MaybeRefOrGetter, PublicProps } from "vue";
import { Errors, UseHttpSubmitOptions, VisitOptions } from "#dapter-native-action-types";
type UseHttpSubmitOptions$1 = UseHttpSubmitOptions<unknown>;
type ActionTransport = 'visit' | 'http';
type ActionDefinition = {
  readonly transports: readonly ActionTransport[];
  readonly decodeInput?: (value: unknown) => unknown;
  readonly decodeResult?: (value: unknown) => unknown;
};
type ActionDefinitions = Readonly<Record<string, ActionDefinition>>;
export declare function defineActions<const A extends ActionDefinitions>(definitions: A): A;
type ActionMetadata = {
  readonly method: 'post' | 'put' | 'patch' | 'delete';
  readonly transports: readonly ActionTransport[];
} & ({
  readonly url: string;
  readonly target: null;
} | {
  readonly target: {
    readonly input: string;
    readonly valueType: 'string' | 'integer';
    readonly values: readonly {
      readonly value: string | number;
      readonly url: string;
    }[];
  };
});
type ActionMetadataMap = Readonly<Record<string, ActionMetadata | undefined>>;
type ActionVisitOptions = Omit<VisitOptions, 'url' | 'method' | 'data' | 'headers'>;
type ActionHttpOptions = Omit<UseHttpSubmitOptions$1, 'headers' | 'optimistic'>;
type WriteOutcome<Result = unknown> = {
  readonly status: 'completed';
  readonly commit: 'confirmed';
  readonly data: Result;
} | {
  readonly status: 'rejected';
  readonly commit: 'not_started';
  readonly error: unknown;
} | {
  readonly status: 'validation';
  readonly commit: 'unknown';
  readonly errors: Errors;
} | {
  readonly status: 'cancelled';
  readonly commit: 'unknown';
} | {
  readonly status: 'failed';
  readonly commit: 'unknown';
  readonly error: unknown;
};
type Ids<A extends ActionDefinitions, T extends ActionTransport> = { [K in keyof A]: T extends A[K]['transports'][number] ? K : never; }[keyof A] & string;
type Input<A> = A extends {
  readonly decodeInput: (value: unknown) => infer I;
} ? I : unknown;
type Output<A> = A extends {
  readonly decodeResult: (value: unknown) => infer O;
} ? O : unknown;
type ActionControls<A extends ActionDefinitions = ActionDefinitions> = {
  readonly available: ActionMetadataMap;
  readonly pending: number;
  readonly error: unknown;
  readonly validation: Errors | null;
  visit<K extends Ids<A, 'visit'>>(id: K, input: Input<A[K]>, options?: ActionVisitOptions): void;
  http<K extends Ids<A, 'http'>>(id: K, input: Input<A[K]>, options?: ActionHttpOptions): Promise<WriteOutcome<Output<A[K]>>>;
};
type FailureKind = 'access' | 'validation' | 'unsupported_operation' | 'server';
type AdapterError = {
  readonly kind: 'cursor_expired';
  readonly code: 'cursor_expired';
  readonly message: string;
  readonly recovery: 'restart';
} | {
  readonly kind: FailureKind | 'protocol' | 'network';
  readonly code: string;
  readonly message: string;
};
type RequestedState = {
  readonly version: 1;
  readonly fields: Readonly<Record<string, never>>;
  readonly global: Readonly<Record<string, never>>;
  readonly searchMatch: 'all';
  readonly order: {
    readonly kind: 'default';
  };
  readonly paging: {
    readonly mode: 'offset';
    readonly current: number;
    readonly per_page: number;
  };
};
type AcceptedState = {
  readonly order: {
    readonly kind: 'source';
  };
  readonly paging: RequestedState['paging'];
};
type Page = {
  readonly data: readonly unknown[];
  readonly requested: RequestedState;
  readonly state: AcceptedState;
  readonly paging: RequestedState['paging'] & {
    readonly pages: number;
    readonly total: number;
    readonly filtered: number;
    readonly has_previous: boolean;
    readonly has_next: boolean;
  };
  readonly options: readonly number[];
  readonly actions?: ActionMetadataMap;
};
type ReadonlyDeep<T> = T extends ((...args: never[]) => unknown) ? T : T extends object ? { readonly [K in keyof T]: ReadonlyDeep<T[K]>; } : T;
type RowId = number | string;
type RowRecords = {
  readonly mode: 'unidentified';
} | {
  readonly mode: 'identified';
  readonly rows: readonly {
    readonly id: RowId;
  }[];
} | {
  readonly mode: 'revisioned';
  readonly format: 'uint64-decimal';
  readonly rows: readonly {
    readonly id: RowId;
    readonly revision: string;
  }[];
};
type ChannelDescriptor = {
  readonly type: 'private' | 'public';
  readonly name: string;
  readonly event: '.dapter.updated';
};
type AdapterMetadata = {
  readonly records: RowRecords;
  readonly channel: ChannelDescriptor | null;
};
type Scalar = string | number | boolean | null;
type MatchType = 'contains' | 'equals' | 'starts_with' | 'ends_with' | 'full_text' | 'vector';
type SortType = 'native' | 'regular' | 'string' | 'natural' | 'numeric' | 'locale';
type FullTextPolicy = {
  readonly engine: 'pgsql';
  readonly configuration: string;
  readonly input: 'plain_text';
  readonly maxBytes: 512;
  readonly ranking: 'filter_only';
};
type LocalePolicy = {
  readonly locale: string;
  readonly validLocale: string;
  readonly actualLocale: string;
  readonly icuVersion: string;
  readonly strength: 'tertiary';
  readonly normalization: true;
  readonly numeric: false;
};
type VectorPolicy = {
  readonly engine: 'pgsql';
  readonly metric: 'l2';
  readonly dimensions: number;
  readonly maxDistance: number;
  readonly input: 'prepared_vector';
  readonly precision: 'float32';
  readonly maxCoordinateMagnitude: 1000000;
  readonly threshold: 'inclusive';
  readonly ranking: 'filter_only';
};
type SearchDefinition = {
  readonly matchTypes: readonly MatchType[];
  readonly valueType: 'string' | 'integer' | 'number' | 'vector';
  readonly fullText?: FullTextPolicy;
  readonly vector?: VectorPolicy;
};
type OrderDefinition = {
  readonly sortTypes: readonly SortType[];
  readonly directions: readonly ('asc' | 'desc')[];
  readonly locale?: LocalePolicy;
};
type FilterDefinition = {
  readonly values: readonly Scalar[];
} & ({
  readonly multiple: false;
} | {
  readonly multiple: true;
  readonly empty: 'clear' | 'reject';
});
type FieldDefinition = {
  readonly search: SearchDefinition | null;
  readonly order: OrderDefinition | null;
  readonly filters: Readonly<Record<string, FilterDefinition | undefined>> | null;
};
type SearchMatch = 'all' | 'any';
type GlobalSearchDefinition = {
  readonly valueType: 'string';
  readonly targets: Readonly<Record<string, {
    readonly valueType: Exclude<SearchDefinition['valueType'], 'vector'>;
    readonly matchType: Exclude<MatchType, 'vector'>;
    readonly fullText?: FullTextPolicy;
  }>>;
};
type GlobalOrderDefinition = {
  readonly presets: readonly {
    readonly id: string;
    readonly label: string;
  }[];
  readonly pagingModes: readonly ['offset'];
};
type StabilityTerm = {
  readonly id: string;
  readonly direction: 'asc' | 'desc';
  readonly sortType: SortType;
};
type StabilityDefinition = {
  readonly prefix: readonly StabilityTerm[];
  readonly identity: readonly StabilityTerm[];
};
type AdapterDefinition = {
  readonly fields: Readonly<Record<string, FieldDefinition | undefined>>;
  readonly global: {
    readonly multi_order: boolean;
    readonly multi_order_limit: number;
    readonly searchMatch: {
      readonly default: SearchMatch;
      readonly allowed: readonly SearchMatch[];
    };
    readonly search: GlobalSearchDefinition | null;
    readonly filters: FieldDefinition['filters'];
    readonly order: GlobalOrderDefinition | null;
    readonly stability?: StabilityDefinition;
  };
  readonly paging: PagingDefinition;
};
type PagingPosition = Page['requested']['paging'] | {
  readonly mode: 'simple';
  readonly current: number;
  readonly per_page: number;
} | {
  readonly mode: 'cursor';
  readonly cursor: string | null;
  readonly per_page: number;
};
type PagingDefinition = {
  readonly per_page: number;
  readonly per_page_options: readonly number[];
} & ({
  readonly mode: 'offset';
} | {
  readonly mode: 'simple';
  readonly counts: 'unknown' | 'exact';
} | {
  readonly mode: 'cursor';
  readonly counts: 'unknown' | 'exact';
  readonly expires_after: 900;
});
type SearchSelection = {
  readonly value: string | number;
  readonly matchType: Exclude<MatchType, 'vector'>;
} | {
  readonly value: readonly number[];
  readonly matchType: 'vector';
};
type FilterSelection = {
  readonly value: Scalar | readonly Scalar[];
};
type OrderSelection = {
  readonly direction: 'asc' | 'desc';
  readonly sortType: SortType;
};
type FieldSelection = {
  readonly search?: SearchSelection;
  readonly filters?: Readonly<Record<string, FilterSelection>>;
};
type RequestedStateV2<Position extends PagingPosition = Page['requested']['paging']> = {
  readonly version: 2;
  readonly fields: Readonly<Record<string, FieldSelection>>;
  readonly global: {
    readonly search?: {
      readonly value: string;
    };
    readonly filters?: Readonly<Record<string, FilterSelection>>;
  };
  readonly searchMatch: SearchMatch;
  readonly order: {
    readonly kind: 'default';
  } | {
    readonly kind: 'fields';
    readonly terms: readonly (OrderSelection & {
      readonly field: string;
    })[];
  } | {
    readonly kind: 'preset';
    readonly id: string;
  };
  readonly paging: Position;
};
type SourceRequestedStateV2 = RequestedStateV2<PagingPosition>;
type AcceptedStateV2<Position extends PagingPosition = Page['requested']['paging']> = {
  readonly order: {
    readonly kind: 'source';
  } | {
    readonly kind: 'terms';
    readonly terms: readonly (OrderSelection & {
      readonly field: string;
      readonly role?: 'prefix' | 'tie';
    })[];
  } | {
    readonly kind: 'preset';
    readonly id: string;
    readonly identity: string;
    readonly opaque: true;
  };
  readonly paging: Position;
};
type DefinitionDecoder<D extends AdapterDefinition = AdapterDefinition> = (definition: ReadonlyDeep<AdapterDefinition>) => D;
type DapterEchoChannel = {
  listen(event: string, callback: (value: unknown) => void): unknown;
  stopListening(event: string, callback: (value: unknown) => void): unknown;
  error(callback: (error: unknown) => void): unknown;
};
type DapterEcho = {
  private(name: string): DapterEchoChannel;
  channel(name: string): DapterEchoChannel;
};
type PollingOptions = false | {
  readonly interval: number;
};
type IntegrationError = {
  readonly kind: 'integration';
  readonly code: 'echo_missing' | 'subscription_failed' | 'invalid_event' | 'decoder_failed' | 'event_overflow' | 'render_failed';
  readonly message: string;
};
type StateOptions = {
  readonly page?: 'first' | 'preserve';
};
type InfiniteOptions = false | true | {
  readonly root: MaybeRefOrGetter<HTMLElement | null>;
  readonly sentinel: MaybeRefOrGetter<HTMLElement | null>;
  readonly axis?: 'y' | 'x';
};
type InfiniteControls = {
  readonly status: 'idle' | 'loading' | 'paused' | 'exhausted';
  readonly append: () => Promise<AsyncOutcome>;
  readonly retry: () => Promise<AsyncOutcome>;
};
type PagingSelection = 'offset' | 'source';
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
type SourceAdapterProps = SourceAdapterBase & {
  protocol?: 1 | 2 | undefined;
  paging?: PagingSelection | undefined;
};
type PagingRequirements<P> = (P extends {
  paging: 'source';
} ? {
  protocol: 2;
} : unknown) & (P extends {
  echo: DapterEcho;
} | {
  polling: {
    readonly interval: number;
  };
} ? {
  protocol: 2;
} : unknown) & (P extends {
  infinite: infer Infinite;
} ? [Infinite] extends [false | undefined] ? unknown : {
  protocol: 2;
} : unknown);
type AnyDataAdapterHandle = DataAdapterHandle<unknown, AdapterDefinition, ActionDefinitions, PagingSelection>;
type DataAdapterProps = (SourceAdapterProps & ({
  protocol: 2;
  paging: 'source';
} | {
  paging?: 'offset' | undefined;
})) | {
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
type ComponentOwnerProps<P> = P extends {
  adapter: AnyDataAdapterHandle;
} ? Extract<DataAdapterProps, {
  adapter: AnyDataAdapterHandle;
}> : P extends {
  paging: 'source';
} ? SourceAdapterProps & {
  paging: 'source';
  protocol: 2;
} : SourceAdapterProps & {
  paging?: 'offset' | undefined;
};
type RowOf<P> = P extends {
  adapter: DataAdapterHandle<infer Row, infer _Definition, infer _Actions, infer _Paging>;
} ? Row : P extends {
  decodeRow: (value: unknown) => infer Row;
} ? Row : unknown;
type AsyncOutcome = {
  readonly status: 'success';
} | {
  readonly status: 'superseded';
} | {
  readonly status: 'failure';
  readonly error: AdapterError;
};
type OffsetPaging = {
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
type PagingControls = Pick<OffsetPaging, 'loading' | 'empty' | 'has_previous' | 'has_next' | 'per_page' | 'goToFirst' | 'goToPrevious' | 'goToNext' | 'reload'>;
type TraversalControls = Omit<PagingControls, 'per_page'> & {
  readonly per_page: Omit<OffsetPaging['per_page'], 'value'> & {
    readonly value: number;
  };
};
type CountView = {
  readonly counts: 'unknown';
  readonly total: null;
  readonly filtered: null;
} | {
  readonly counts: 'exact';
  readonly total: number;
  readonly filtered: number;
};
type SimplePaging = TraversalControls & CountView & {
  readonly mode: 'simple';
  readonly current: number;
  readonly pages: null;
  readonly previous_cursor: null;
  readonly next_cursor: null;
  readonly goToPage: null;
  readonly goToLast: null;
};
type CursorPaging = TraversalControls & CountView & {
  readonly mode: 'cursor';
  readonly current: null;
  readonly pages: null;
  readonly previous_cursor: string | null;
  readonly next_cursor: string | null;
  readonly goToPage: null;
  readonly goToLast: null;
};
type UnreadyPaging = Omit<PagingControls, 'empty' | 'has_previous' | 'has_next' | 'per_page'> & {
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
type SourcePaging = UnreadyPaging | OffsetPaging | SimplePaging | CursorPaging;
type PagingOf<P> = P extends {
  adapter: {
    paging: OffsetPaging;
  };
} ? 'offset' : P extends {
  adapter: AnyDataAdapterHandle;
} ? 'source' : [P] extends [{
  paging: 'source';
}] ? 'source' : 'offset';
type DefinitionOf<P> = P extends {
  adapter: DataAdapterHandle<infer _Row, infer Definition, infer _Actions, infer _Paging>;
} ? Definition : P extends {
  decodeDefinition: (value: ReadonlyDeep<AdapterDefinition>) => infer Definition extends AdapterDefinition;
} ? Definition : AdapterDefinition;
type ActionsOf<P> = P extends {
  adapter: DataAdapterHandle<infer _Row, infer _Definition, infer A, infer _Paging>;
} ? A : P extends {
  actionDefinitions: infer A extends ActionDefinitions;
} ? A : ActionDefinitions;
type DataAdapterSlot<P = SourceAdapterProps> = DataAdapterHandle<RowOf<P>, DefinitionOf<P>, ActionsOf<P>, PagingOf<P>>;
type FieldCapability<Available, Current, Input> = Readonly<{
  readonly available: Available;
  readonly current: Current | null;
  call(value: Input, options?: StateOptions): Promise<AsyncOutcome>;
  clear(options?: StateOptions): Promise<AsyncOutcome>;
}>;
type SearchValue<T> = T extends 'vector' ? readonly number[] : T extends 'string' ? string : T extends 'integer' | 'number' ? number : never;
type SearchInput<S extends SearchDefinition> = S extends {
  readonly valueType: 'vector';
} ? {
  readonly value: readonly number[];
  readonly matchType: 'vector';
} : S extends {
  readonly valueType: 'string' | 'integer' | 'number';
} ? {
  readonly value: SearchValue<S['valueType']>;
  readonly matchType: Exclude<S['matchTypes'][number], 'vector'>;
} : SearchSelection;
type FilterInput<F extends FilterDefinition> = {
  readonly value: F extends {
    readonly multiple: true;
  } ? F extends {
    readonly empty: 'reject';
  } ? readonly [F['values'][number], ...F['values'][number][]] : readonly F['values'][number][] : F['values'][number];
};
type OrderInput<O extends OrderDefinition, Multi extends boolean = false> = {
  readonly direction: O['directions'][number];
  readonly sortType: O['sortTypes'][number];
  readonly multiSort?: Multi;
};
type SearchCapability<S> = S extends SearchDefinition ? FieldCapability<S, SearchInput<S>, SearchInput<S>> : null;
type OrderCapability<O, Multi extends boolean> = O extends OrderDefinition ? FieldCapability<O, Omit<OrderInput<O, Multi>, 'multiSort'> & {
  readonly priority: number;
}, OrderInput<O, Multi>> : null;
type FilterCapabilities<F> = F extends Readonly<Record<string, FilterDefinition | undefined>> ? { readonly [K in keyof F]?: F[K] extends FilterDefinition ? FieldCapability<F[K], FilterInput<F[K]>, FilterInput<F[K]>> : FieldCapability<NonNullable<F[K]>, FilterSelection, FilterSelection>; } : null;
type FieldView<F extends FieldDefinition = FieldDefinition, Multi extends boolean = boolean> = {
  readonly search: SearchCapability<F['search']>;
  readonly order: OrderCapability<F['order'], Multi>;
  readonly filters: FilterCapabilities<F['filters']>;
};
type FieldsView<D extends AdapterDefinition = AdapterDefinition> = { readonly [K in keyof D['fields']]?: FieldView<NonNullable<D['fields'][K]>, D['global']['multi_order']>; };
type GlobalView<D extends AdapterDefinition = AdapterDefinition> = Omit<D['global'], 'search' | 'filters' | 'order'> & {
  readonly search: D['global']['search'] extends null ? null : FieldCapability<NonNullable<D['global']['search']>, {
    readonly value: string;
  }, {
    readonly value: string;
    readonly targets?: never;
    readonly matchType?: never;
  }> | (null extends D['global']['search'] ? null : never);
  readonly filters: FilterCapabilities<D['global']['filters']>;
  readonly order: D['global']['order'] extends null ? null : FieldCapability<NonNullable<D['global']['order']>, {
    readonly id: NonNullable<D['global']['order']>['presets'][number]['id'];
  }, {
    readonly id: NonNullable<D['global']['order']>['presets'][number]['id'];
    readonly direction?: never;
    readonly sortType?: never;
    readonly multiSort?: never;
  }> | (null extends D['global']['order'] ? null : never);
};
type DataAdapterHandle<Row = unknown, Definition extends AdapterDefinition = AdapterDefinition, Actions extends ActionDefinitions = ActionDefinitions, Paging extends PagingSelection = 'offset'> = {
  readonly status: 'idle' | 'loading' | 'success' | 'error';
  readonly error: AdapterError | null;
  readonly integrationError: IntegrationError | null;
  readonly share: () => string | null;
  readonly resetContext: () => Promise<AsyncOutcome>;
  readonly restartPolling: () => void;
  readonly infinite: InfiniteControls | null;
  readonly requested: RequestedState | (Paging extends 'source' ? SourceRequestedStateV2 : RequestedStateV2) | null;
  readonly accepted: AcceptedState | AcceptedStateV2<Paging extends 'source' ? PagingPosition : RequestedStateV2['paging']> | null;
  readonly definition: ReadonlyDeep<Definition> | null;
  readonly capabilities: ReadonlyDeep<Definition> | null;
  readonly fields: FieldsView<Definition> | null;
  readonly metadata: AdapterMetadata | null;
  readonly global: GlobalView<Definition> | null;
  readonly setSearchMatch: {
    call(value: Definition['global']['searchMatch']['allowed'][number], options?: StateOptions): Promise<AsyncOutcome>;
  }['call'] | null;
  readonly actions: ActionControls<Actions> | null;
  readonly replaceState: (value: unknown) => Promise<AsyncOutcome>;
  readonly resetState: () => Promise<AsyncOutcome>;
  readonly paging: Paging extends 'source' ? SourcePaging : OffsetPaging;
} & ({
  readonly ready: false;
  readonly data: readonly [];
} | {
  readonly ready: true;
  readonly data: readonly ReadonlyDeep<Row>[];
});
type ComponentSlot<P extends DataAdapterProps, Row, Definition extends AdapterDefinition, Actions extends ActionDefinitions = ActionsOf<P>> = [P] extends [{
  adapter: AnyDataAdapterHandle;
}] ? DataAdapterSlot<P> : DataAdapterHandle<Row, Definition, Actions, PagingOf<P>>;
type ComponentDecoderRequirements<P extends DataAdapterProps, Row, Definition extends AdapterDefinition, Actions extends ActionDefinitions = ActionsOf<P>> = [P] extends [{
  adapter: AnyDataAdapterHandle;
}] ? unknown : PagingRequirements<Omit<P, 'infinite'>> & (P extends {
  infinite: unknown;
} ? {
  infinite?: false | undefined;
} | {
  protocol: 2;
} : unknown) & (unknown extends Row ? unknown : {
  decodeRow: (value: unknown) => Row;
}) & (AdapterDefinition extends Definition ? unknown : {
  decodeDefinition: DefinitionDecoder<Definition>;
}) & (ActionDefinitions extends Actions ? unknown : {
  actionDefinitions: Actions;
});
declare const __VLS_export: <P extends DataAdapterProps & Record<Exclude<keyof P, keyof DataAdapterProps | keyof PublicProps>, never>, Row = RowOf<P>, Definition extends AdapterDefinition = DefinitionOf<P>, Actions extends ActionDefinitions = ActionsOf<P>>(__VLS_props: NonNullable<Awaited<typeof __VLS_setup>>["props"], __VLS_ctx?: __VLS_PrettifyLocal<Pick<NonNullable<Awaited<typeof __VLS_setup>>, "attrs" | "emit" | "slots">>, __VLS_exposed?: NonNullable<Awaited<typeof __VLS_setup>>["expose"], __VLS_setup?: Promise<{
  props: import("vue").PublicProps & __VLS_PrettifyLocal<(P & DataAdapterProps) & ComponentOwnerProps<P> & {
    decodeRow?: ((value: unknown) => Row) | undefined;
    decodeDefinition?: DefinitionDecoder<Definition> | undefined;
    actionDefinitions?: Actions | undefined;
  } & ComponentDecoderRequirements<P, Row, Definition, Actions>> & (typeof globalThis extends {
    __VLS_PROPS_FALLBACK: infer P_1;
  } ? P_1 : {});
  expose: (exposed: import("vue").ShallowUnwrapRef<{
    share: () => string | null;
  }>) => void;
  attrs: any;
  slots: {
    default(state: ComponentSlot<P, Row, Definition, Actions>): unknown;
  };
  emit: {};
}>) => import("vue").VNode & {
  __ctx?: NonNullable<Awaited<typeof __VLS_setup>>;
};
declare const _default: typeof __VLS_export;
type __VLS_PrettifyLocal<T> = (T extends any ? { [K in keyof T]: T[K]; } : { [K in keyof T as K]: T[K]; }) & {};
type DataAdapterOptions = Omit<SourceAdapterProps, 'source' | 'adapter' | 'protocol' | 'paging' | 'echo' | 'polling' | 'infinite'> & ({
  infinite?: SourceAdapterProps['infinite'];
  protocol: 2;
  paging: 'source';
  echo?: SourceAdapterProps['echo'];
  polling?: SourceAdapterProps['polling'];
} | {
  infinite?: SourceAdapterProps['infinite'];
  protocol: 2;
  paging?: 'offset' | undefined;
  echo?: SourceAdapterProps['echo'];
  polling?: SourceAdapterProps['polling'];
} | {
  infinite?: false | undefined;
  protocol?: 1 | undefined;
  paging?: 'offset' | undefined;
  echo?: never;
  polling?: false | undefined;
});
export declare function useDataAdapter<H extends AnyDataAdapterHandle>(adapter: H): H;
export declare function useDataAdapter<O extends DataAdapterOptions>(source: string, options: O & PagingRequirements<O> & Record<Exclude<keyof O, keyof DataAdapterOptions>, never>): DataAdapterSlot<O & {
  source: string;
}>;
export declare function useDataAdapter<O extends DataAdapterOptions & {
  id: string;
}>(source: MaybeRefOrGetter<string>, options: O & PagingRequirements<O> & Record<Exclude<keyof O, keyof DataAdapterOptions>, never>): DataAdapterSlot<O & {
  source: string;
}>;
export declare function useDataAdapter<Row>(source: string, options: DataAdapterOptions & {
  paging?: 'offset';
  decodeRow: (value: unknown) => Row;
}): DataAdapterHandle<Row>;
export declare function useDataAdapter<Row>(source: MaybeRefOrGetter<string>, options: DataAdapterOptions & {
  paging?: 'offset';
  id: string;
  decodeRow: (value: unknown) => Row;
}): DataAdapterHandle<Row>;
export declare function useDataAdapter(source: string): DataAdapterHandle;
export declare const version: string;
export { type AcceptedState, type AcceptedStateV2, type ActionControls, type ActionDefinition, type ActionDefinitions, type ActionHttpOptions, type ActionMetadata, type ActionMetadataMap, type ActionTransport, type ActionVisitOptions, type ActionsOf, type AdapterDefinition, type AdapterError, type AdapterMetadata, type AsyncOutcome, type ChannelDescriptor, type CursorPaging, type DapterEcho, type DapterEchoChannel, _default as DataAdapter, type DataAdapterHandle, type DataAdapterOptions, type DataAdapterProps, type DataAdapterSlot, type DefinitionDecoder, type FieldCapability, type FieldDefinition, type FieldSelection, type FieldView, type FieldsView, type FilterDefinition, type FilterInput, type FilterSelection, type FullTextPolicy, type GlobalOrderDefinition, type GlobalSearchDefinition, type GlobalView, type InfiniteControls, type InfiniteOptions, type IntegrationError, type LocalePolicy, type MatchType, type OffsetPaging, type OrderDefinition, type OrderInput, type OrderSelection, type PagingDefinition, type PagingPosition, type PagingSelection, type PollingOptions, type ReadonlyDeep, type RequestedState, type RequestedStateV2, type RowId, type RowRecords, type Scalar, type SearchDefinition, type SearchInput, type SearchMatch, type SearchSelection, type SimplePaging, type SortType, type SourcePaging, type SourceRequestedStateV2, type StabilityDefinition, type StabilityTerm, type StateOptions, type UnreadyPaging, type VectorPolicy, type WriteOutcome };
//# sourceMappingURL=index.d.ts.map