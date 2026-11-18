import { h, reactive } from 'vue';
import {
    useDataAdapter,
    type AdapterDefinition,
    type ActionDefinitions,
    type DataAdapterHandle,
    type DataAdapterOptions,
    type SourceRequestedStateV2,
    type PagingPosition,
} from '@artisan-toolbox/dapter';
import { definition, mountTree } from './phase2-fixtures.js';

export type SourceHandle = DataAdapterHandle<
    unknown,
    AdapterDefinition,
    ActionDefinitions,
    'source'
>;
export function advancedDefinition(
    mode: 'simple' | 'cursor' = 'cursor',
    counts: 'unknown' | 'exact' = 'unknown',
): AdapterDefinition {
    return {
        ...definition,
        fields: {
            ...definition.fields,
            name: {
                ...definition.fields.name,
                order: { directions: ['asc', 'desc'], sortTypes: ['native', 'natural'] },
            },
        },
        global: {
            ...definition.global,
            searchMatch: { default: 'all', allowed: ['all', 'any'] },
            multi_order: true,
            multi_order_limit: 2,
            search: {
                valueType: 'string',
                targets: { name: { valueType: 'string', matchType: 'contains' } },
            },
            filters: { status: { values: ['active', 'archived'], multiple: false } },
            order: { presets: [{ id: 'featured', label: 'Featured' }], pagingModes: ['offset'] },
        },
        paging:
            mode === 'cursor'
                ? { mode, per_page: 2, per_page_options: [2, 3], counts, expires_after: 900 }
                : { mode, per_page: 2, per_page_options: [2, 3], counts },
    };
}
export function advancedIntent(
    paging: PagingPosition = { mode: 'cursor', cursor: null, per_page: 2 },
    patch: Partial<SourceRequestedStateV2> = {},
): SourceRequestedStateV2 {
    return {
        version: 2,
        fields: {},
        global: {},
        searchMatch: 'all',
        order: { kind: 'default' },
        paging,
        ...patch,
    };
}
export function advancedPage(
    state = advancedIntent(),
    options: {
        counts?: 'unknown' | 'exact';
        previous?: string | null;
        next?: string | null;
        data?: readonly unknown[];
        capabilities?: AdapterDefinition;
    } = {},
) {
    if (state.paging.mode === 'offset')
        throw new Error('Advanced fixture requires traversal paging');
    const previous = options.previous ?? null,
        next = options.next === undefined ? 'next-token' : options.next;
    const count = options.counts ?? 'unknown';
    return {
        version: 2,
        data: options.data ?? [{ id: 1 }, { id: 2 }],
        requested: state,
        state: {
            order:
                state.order.kind === 'fields'
                    ? { kind: 'terms', terms: state.order.terms }
                    : { kind: 'source' },
            paging: state.paging,
        },
        paging: {
            ...state.paging,
            ...(state.paging.mode === 'cursor' ? { current: null } : {}),
            pages: null,
            total: count === 'exact' ? 6 : null,
            filtered: count === 'exact' ? 6 : null,
            previous_cursor: state.paging.mode === 'cursor' ? previous : null,
            next_cursor: state.paging.mode === 'cursor' ? next : null,
            has_previous:
                state.paging.mode === 'cursor' ? previous !== null : state.paging.current > 1,
            has_next: next !== null,
        },
        capabilities: options.capabilities ?? advancedDefinition(state.paging.mode, count),
        metadata: { records: { mode: 'unidentified' }, channel: null },
    };
}
export function mountAdvanced(options: DataAdapterOptions = {}) {
    const source = reactive({ value: 'Feed' });
    let handle: SourceHandle | undefined;
    const unmount = mountTree(() => {
        handle = useDataAdapter(() => source.value, {
            ...options,
            protocol: 2,
            paging: 'source',
            id: options.id ?? 'phase-four',
            url: false,
        });
        return () => h('output');
    });
    if (!handle) throw new Error('Missing source handle');
    return { handle, source, unmount };
}
