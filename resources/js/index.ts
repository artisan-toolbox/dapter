import metadata from '../../package.json' with { type: 'json' };

export const version: string = metadata.version;
export { default as DataAdapter } from './DataAdapter.vue';
export type {
    DataAdapterProps,
    DataAdapterHandle,
    DataAdapterSlot,
    AsyncOutcome,
    AdapterError,
    OffsetPaging,
    ReadonlyDeep,
} from './adapter.js';
export { useDataAdapter, type DataAdapterOptions } from './useDataAdapter.js';
