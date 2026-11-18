import metadata from '../../package.json' with { type: 'json' };

export const version: string = metadata.version;
export { default as DataAdapter } from './DataAdapter.vue';
export type {
    DataAdapterProps,
    DataAdapterHandle,
    DataAdapterSlot,
    AsyncOutcome,
    InfiniteOptions,
    InfiniteControls,
    AdapterError,
    OffsetPaging,
    SimplePaging,
    CursorPaging,
    UnreadyPaging,
    SourcePaging,
    PagingSelection,
    ReadonlyDeep,
} from './adapter.js';
export { useDataAdapter, type DataAdapterOptions } from './useDataAdapter.js';

export type {
    AdapterDefinition,
    AdapterMetadata,
    FieldDefinition,
    SearchDefinition,
    FullTextPolicy,
    VectorPolicy,
    LocalePolicy,
    OrderDefinition,
    FilterDefinition,
    DefinitionDecoder,
    Scalar,
    MatchType,
    SortType,
    SearchSelection,
    FilterSelection,
    FieldSelection,
    OrderSelection,
    RequestedStateV2,
    SourceRequestedStateV2,
    PagingPosition,
    PagingDefinition,
    AcceptedStateV2,
} from './protocol-v2.js';
export type { RequestedState, AcceptedState } from './protocol.js';
export type { StateOptions } from './requested-state.js';
export type {
    FieldsView,
    FieldView,
    FieldCapability,
    SearchInput,
    FilterInput,
    OrderInput,
} from './adapter.js';

export { defineActions } from './actions.js';
export type {
    ActionDefinitions,
    ActionDefinition,
    ActionControls,
    ActionMetadata,
    ActionMetadataMap,
    ActionTransport,
    ActionVisitOptions,
    ActionHttpOptions,
    WriteOutcome,
} from './actions.js';
export type { GlobalView, ActionsOf } from './adapter.js';
export type {
    GlobalSearchDefinition,
    GlobalOrderDefinition,
    SearchMatch,
    StabilityDefinition,
    StabilityTerm,
} from './protocol-v2.js';

export type {
    DapterEcho,
    DapterEchoChannel,
    PollingOptions,
    IntegrationError,
} from './realtime.js';
export type { RowId, RowRecords, ChannelDescriptor } from './records.js';
