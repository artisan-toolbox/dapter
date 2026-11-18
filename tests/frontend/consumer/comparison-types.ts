import {
    DataAdapter,
    useDataAdapter,
    type AdapterDefinition,
    type DataAdapterSlot,
    type FullTextPolicy,
    type LocalePolicy,
    type ReadonlyDeep,
} from '@artisan-toolbox/dapter';

const fullText = {
    engine: 'pgsql',
    configuration: 'pg_catalog.english',
    input: 'plain_text',
    maxBytes: 512,
    ranking: 'filter_only',
} as const satisfies FullTextPolicy;
const locale = {
    locale: 'pt_BR',
    validLocale: 'pt',
    actualLocale: 'root',
    icuVersion: '78.1',
    strength: 'tertiary',
    normalization: true,
    numeric: false,
} as const satisfies LocalePolicy;
export function decodeComparisonDefinition(value: ReadonlyDeep<AdapterDefinition>) {
    return {
        ...value,
        fields: {
            name: {
                search: { valueType: 'string', matchTypes: ['full_text'], fullText },
                order: { directions: ['asc'], sortTypes: ['locale'], locale },
                filters: null,
            },
            status: {
                search: null,
                order: null,
                filters: { selected: { values: [null, false, 0, '0', 'active'], multiple: false } },
            },
        },
        global: {
            ...value.global,
            multi_order: false,
            search: {
                valueType: 'string',
                targets: { name: { valueType: 'string', matchType: 'full_text', fullText } },
            },
        },
    } as const satisfies AdapterDefinition;
}
export function decodeLiteralDefinition(value: ReadonlyDeep<AdapterDefinition>) {
    const comparison = decodeComparisonDefinition(value);
    return {
        ...comparison,
        fields: {
            ...comparison.fields,
            name: {
                ...comparison.fields.name,
                search: {
                    valueType: 'string',
                    matchTypes: ['contains', 'equals', 'starts_with', 'ends_with'],
                },
            },
        },
        global: {
            ...comparison.global,
            search: {
                valueType: 'string',
                targets: { name: { valueType: 'string', matchType: 'contains' } },
            },
        },
    } as const satisfies AdapterDefinition;
}
export function decodeResourceRow(value: unknown) {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('id' in value) ||
        typeof value.id !== 'number' ||
        !('name' in value) ||
        typeof value.name !== 'string'
    )
        throw new Error('Invalid Resource row');
    return { record: { id: value.id, name: value.name }, nullable: null };
}
const props = {
    source: 'Documents',
    protocol: 2,
    decodeDefinition: decodeComparisonDefinition,
    decodeRow: decodeResourceRow,
} as const;
export function inspectComparisons(slot: DataAdapterSlot<typeof props>) {
    void slot.fields?.name?.search.call({ value: 'running cats', matchType: 'full_text' });
    void slot.fields?.name?.order.call({ direction: 'asc', sortType: 'locale' });
    void slot.fields?.status?.filters.selected?.call({ value: null });
    void slot.fields?.status?.filters.selected?.call({ value: false });
    void slot.fields?.status?.filters.selected?.call({ value: 0 });
    void slot.fields?.status?.filters.selected?.call({ value: '0' });
    void slot.global?.search.call({ value: 'running cats' });
    const input: 'plain_text' | undefined = slot.fields?.name?.search.available.fullText.input;
    const strength: 'tertiary' | undefined = slot.fields?.name?.order.available.locale.strength;
    void input;
    void strength;
    // @ts-expect-error Match operations remain finite for this field.
    void slot.fields?.name?.search.call({ value: 'cat', matchType: 'contains' });
    // @ts-expect-error Full-text does not accept numeric operands.
    void slot.fields?.name?.search.call({ value: 1, matchType: 'full_text' });
    // @ts-expect-error Sort modes remain finite for this field.
    void slot.fields?.name?.order.call({ direction: 'asc', sortType: 'native' });
    // @ts-expect-error Sort directions remain finite for this field.
    void slot.fields?.name?.order.call({ direction: 'desc', sortType: 'locale' });
    void slot.fields?.name?.search.call({
        value: 'cat',
        matchType: 'full_text',
        // @ts-expect-error Requests cannot replace trusted policy.
        configuration: 'pg_catalog.simple',
    });
    // @ts-expect-error Global targets remain fixed.
    void slot.global?.search.call({ value: 'cat', targets: ['name'] });
    // @ts-expect-error Finite scalar operands do not widen to arbitrary strings.
    void slot.fields?.status?.filters.selected?.call({ value: 'private' });
    // @ts-expect-error Null input is distinct from a selected null scalar.
    void slot.fields?.status?.filters.selected?.call(null);
    // @ts-expect-error Permission checks may remove the field.
    void slot.fields?.name.search;
    // @ts-expect-error Projected Resource keys never grant operations.
    void slot.fields?.record;
    if (slot.ready) {
        const name: string | undefined = slot.data[0]?.record.name;
        const nullable: null | undefined = slot.data[0]?.nullable;
        void name;
        void nullable;
        // @ts-expect-error The decoded Resource projection has no root name.
        void slot.data[0]?.name;
    }
}
export function inspectBorrowedComparisons() {
    const creator = useDataAdapter('Documents', {
        protocol: 2,
        decodeDefinition: decodeComparisonDefinition,
        decodeRow: decodeResourceRow,
    });
    const borrowed = useDataAdapter(creator);
    const bound = DataAdapter({ adapter: borrowed });
    type Slot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
    const inspect = (slot: Slot) => inspectComparisons(slot);
    void inspect;
    const definitionOnly = useDataAdapter('Documents', {
        protocol: 2,
        decodeDefinition: decodeComparisonDefinition,
    });
    if (definitionOnly.ready) {
        // @ts-expect-error Definition inference does not decode Resource rows.
        void definitionOnly.data[0]?.record;
    }
}
