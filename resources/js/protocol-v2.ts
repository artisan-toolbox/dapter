import { parseMetadata, parseRows, type AdapterMetadata } from './records.js';
export type { AdapterMetadata } from './records.js';
import { parseActions } from './actions.js';
import {
    object,
    freeze,
    dictionary,
    parsePage as parseLegacy,
    type ReadonlyDeep,
    type Page as LegacyPage,
    validateJson,
} from './protocol.js';

export type Scalar = string | number | boolean | null;
export type MatchType =
    | 'contains'
    | 'equals'
    | 'starts_with'
    | 'ends_with'
    | 'full_text'
    | 'vector';
export type SortType = 'native' | 'regular' | 'string' | 'natural' | 'numeric' | 'locale';
export type FullTextPolicy = {
    readonly engine: 'pgsql';
    readonly configuration: string;
    readonly input: 'plain_text';
    readonly maxBytes: 512;
    readonly ranking: 'filter_only';
};
export type LocalePolicy = {
    readonly locale: string;
    readonly validLocale: string;
    readonly actualLocale: string;
    readonly icuVersion: string;
    readonly strength: 'tertiary';
    readonly normalization: true;
    readonly numeric: false;
};
export type VectorPolicy = {
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
export type SearchDefinition = {
    readonly matchTypes: readonly MatchType[];
    readonly valueType: 'string' | 'integer' | 'number' | 'vector';
    readonly fullText?: FullTextPolicy;
    readonly vector?: VectorPolicy;
};
export type OrderDefinition = {
    readonly sortTypes: readonly SortType[];
    readonly directions: readonly ('asc' | 'desc')[];
    readonly locale?: LocalePolicy;
};
export type FilterDefinition = { readonly values: readonly Scalar[] } & (
    | { readonly multiple: false }
    | { readonly multiple: true; readonly empty: 'clear' | 'reject' }
);
export type FieldDefinition = {
    readonly search: SearchDefinition | null;
    readonly order: OrderDefinition | null;
    readonly filters: Readonly<Record<string, FilterDefinition | undefined>> | null;
};
export type SearchMatch = 'all' | 'any';
export type GlobalSearchDefinition = {
    readonly valueType: 'string';
    readonly targets: Readonly<
        Record<
            string,
            {
                readonly valueType: Exclude<SearchDefinition['valueType'], 'vector'>;
                readonly matchType: Exclude<MatchType, 'vector'>;
                readonly fullText?: FullTextPolicy;
            }
        >
    >;
};
export type GlobalOrderDefinition = {
    readonly presets: readonly { readonly id: string; readonly label: string }[];
    readonly pagingModes: readonly ['offset'];
};
export type StabilityTerm = {
    readonly id: string;
    readonly direction: 'asc' | 'desc';
    readonly sortType: SortType;
};
export type StabilityDefinition = {
    readonly prefix: readonly StabilityTerm[];
    readonly identity: readonly StabilityTerm[];
};
export type AdapterDefinition = {
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
export type PagingPosition =
    | LegacyPage['requested']['paging']
    | { readonly mode: 'simple'; readonly current: number; readonly per_page: number }
    | { readonly mode: 'cursor'; readonly cursor: string | null; readonly per_page: number };
export type PagingDefinition = {
    readonly per_page: number;
    readonly per_page_options: readonly number[];
} & (
    | { readonly mode: 'offset' }
    | { readonly mode: 'simple'; readonly counts: 'unknown' | 'exact' }
    | { readonly mode: 'cursor'; readonly counts: 'unknown' | 'exact'; readonly expires_after: 900 }
);
export type AdvancedResultPaging = {
    readonly pages: null;
    readonly total: number | null;
    readonly filtered: number | null;
    readonly has_previous: boolean;
    readonly has_next: boolean;
} & (
    | {
          readonly mode: 'simple';
          readonly current: number;
          readonly per_page: number;
          readonly previous_cursor: null;
          readonly next_cursor: null;
      }
    | {
          readonly mode: 'cursor';
          readonly current: null;
          readonly cursor: string | null;
          readonly per_page: number;
          readonly previous_cursor: string | null;
          readonly next_cursor: string | null;
      }
);
export type SearchSelection =
    | { readonly value: string | number; readonly matchType: Exclude<MatchType, 'vector'> }
    | { readonly value: readonly number[]; readonly matchType: 'vector' };
export type FilterSelection = { readonly value: Scalar | readonly Scalar[] };
export type OrderSelection = { readonly direction: 'asc' | 'desc'; readonly sortType: SortType };
export type FieldSelection = {
    readonly search?: SearchSelection;
    readonly filters?: Readonly<Record<string, FilterSelection>>;
};
export type RequestedStateV2<Position extends PagingPosition = LegacyPage['requested']['paging']> =
    {
        readonly version: 2;
        readonly fields: Readonly<Record<string, FieldSelection>>;
        readonly global: {
            readonly search?: { readonly value: string };
            readonly filters?: Readonly<Record<string, FilterSelection>>;
        };
        readonly searchMatch: SearchMatch;
        readonly order:
            | { readonly kind: 'default' }
            | {
                  readonly kind: 'fields';
                  readonly terms: readonly (OrderSelection & { readonly field: string })[];
              }
            | { readonly kind: 'preset'; readonly id: string };
        readonly paging: Position;
    };
export type SourceRequestedStateV2 = RequestedStateV2<PagingPosition>;
export type AcceptedStateV2<Position extends PagingPosition = LegacyPage['requested']['paging']> = {
    readonly order:
        | { readonly kind: 'source' }
        | {
              readonly kind: 'terms';
              readonly terms: readonly (OrderSelection & {
                  readonly field: string;
                  readonly role?: 'prefix' | 'tie';
              })[];
          }
        | {
              readonly kind: 'preset';
              readonly id: string;
              readonly identity: string;
              readonly opaque: true;
          };
    readonly paging: Position;
};
export type BrowserStatePolicy = {
    readonly key: string;
    readonly version: string;
    readonly expiration: { readonly minutes: number } | { readonly at: number };
};
export type PageV2 = Omit<LegacyPage, 'requested' | 'state' | 'paging'> & {
    readonly browserState?: BrowserStatePolicy;
    readonly requested: SourceRequestedStateV2;
    readonly state: AcceptedStateV2<PagingPosition>;
    readonly paging: LegacyPage['paging'] | AdvancedResultPaging;
    readonly definition: AdapterDefinition;
    readonly metadata: AdapterMetadata;
};
function identifier(value: string): void {
    if (!/^[A-Za-z_][A-Za-z_0-9]*$/.test(value)) throw new Error('Invalid identifier');
}
export function exact(
    value: Record<string, unknown>,
    required: readonly string[],
    optional: readonly string[] = [],
): void {
    if (
        required.some((key) => !Object.hasOwn(value, key)) ||
        Object.keys(value).some((key) => !required.includes(key) && !optional.includes(key))
    )
        throw new Error('Invalid properties');
}
export function positive(value: unknown): number {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1)
        throw new Error('Invalid integer');
    return value;
}
function scalar(value: unknown): Scalar {
    if (
        value === null ||
        typeof value === 'string' ||
        typeof value === 'boolean' ||
        (typeof value === 'number' &&
            Number.isFinite(value) &&
            Math.abs(value) <= Number.MAX_SAFE_INTEGER)
    )
        return value;
    throw new Error('Invalid scalar');
}
function match(value: unknown): MatchType {
    if (
        value === 'contains' ||
        value === 'equals' ||
        value === 'starts_with' ||
        value === 'ends_with' ||
        value === 'full_text' ||
        value === 'vector'
    )
        return value;
    throw new Error('Invalid match');
}
function sort(value: unknown): SortType {
    if (
        value === 'native' ||
        value === 'regular' ||
        value === 'string' ||
        value === 'natural' ||
        value === 'numeric' ||
        value === 'locale'
    )
        return value;
    throw new Error('Invalid sort');
}
function direction(value: unknown): 'asc' | 'desc' {
    if (value === 'asc' || value === 'desc') return value;
    throw new Error('Invalid direction');
}
function list<T>(value: unknown, parse: (input: unknown) => T): readonly T[] {
    if (!Array.isArray(value) || !value.length) throw new Error('Invalid list');
    const parsed = value.map(parse);
    if (new Set(parsed).size !== parsed.length) throw new Error('Duplicate values');
    return parsed;
}
function parseFullText(value: unknown): FullTextPolicy {
    const input = object(value);
    exact(input, ['engine', 'configuration', 'input', 'maxBytes', 'ranking']);
    if (
        input.engine !== 'pgsql' ||
        typeof input.configuration !== 'string' ||
        !/^[A-Za-z_][A-Za-z_0-9]*\.[A-Za-z_][A-Za-z_0-9]*$/.test(input.configuration) ||
        input.input !== 'plain_text' ||
        input.maxBytes !== 512 ||
        input.ranking !== 'filter_only'
    )
        throw new Error('Invalid full-text policy');
    return {
        engine: 'pgsql',
        configuration: input.configuration,
        input: 'plain_text',
        maxBytes: 512,
        ranking: 'filter_only',
    };
}
function parseLocale(value: unknown): LocalePolicy {
    const input = object(value);
    exact(input, [
        'locale',
        'validLocale',
        'actualLocale',
        'icuVersion',
        'strength',
        'normalization',
        'numeric',
    ]);
    if (
        typeof input.locale !== 'string' ||
        !input.locale ||
        typeof input.validLocale !== 'string' ||
        !input.validLocale ||
        typeof input.actualLocale !== 'string' ||
        !input.actualLocale ||
        typeof input.icuVersion !== 'string' ||
        !input.icuVersion ||
        input.strength !== 'tertiary' ||
        input.normalization !== true ||
        input.numeric !== false
    )
        throw new Error('Invalid locale policy');
    return {
        locale: input.locale,
        validLocale: input.validLocale,
        actualLocale: input.actualLocale,
        icuVersion: input.icuVersion,
        strength: 'tertiary',
        normalization: true,
        numeric: false,
    };
}
function parseVectorPolicy(value: unknown): VectorPolicy {
    const input = object(value);
    exact(input, [
        'engine',
        'metric',
        'dimensions',
        'maxDistance',
        'input',
        'precision',
        'maxCoordinateMagnitude',
        'threshold',
        'ranking',
    ]);
    if (
        input.engine !== 'pgsql' ||
        input.metric !== 'l2' ||
        typeof input.dimensions !== 'number' ||
        !Number.isSafeInteger(input.dimensions) ||
        input.dimensions < 1 ||
        input.dimensions > 16000 ||
        typeof input.maxDistance !== 'number' ||
        !Number.isFinite(input.maxDistance) ||
        input.maxDistance < 0 ||
        input.maxDistance > Number.MAX_SAFE_INTEGER ||
        input.input !== 'prepared_vector' ||
        input.precision !== 'float32' ||
        input.maxCoordinateMagnitude !== 1000000 ||
        input.threshold !== 'inclusive' ||
        input.ranking !== 'filter_only'
    )
        throw new Error('Invalid vector policy');
    return {
        engine: 'pgsql',
        metric: 'l2',
        dimensions: input.dimensions,
        maxDistance: input.maxDistance,
        input: 'prepared_vector',
        precision: 'float32',
        maxCoordinateMagnitude: 1000000,
        threshold: 'inclusive',
        ranking: 'filter_only',
    };
}
function parseVector(value: unknown, policy?: VectorPolicy): readonly number[] {
    if (
        !Array.isArray(value) ||
        value.length < 1 ||
        value.length > 16000 ||
        (policy && value.length !== policy.dimensions)
    )
        throw new Error('Invalid vector dimensions');
    return Array.from(value, (coordinate: unknown) => {
        if (
            typeof coordinate !== 'number' ||
            !Number.isFinite(coordinate) ||
            Math.abs(coordinate) > 1000000
        )
            throw new Error('Invalid vector coordinate');
        return Math.fround(coordinate);
    });
}
function parseSearch(value: unknown): SearchDefinition | null {
    if (value === null) return null;
    const input = object(value);
    exact(input, ['matchTypes', 'valueType'], ['fullText', 'vector']);
    if (input.valueType === 'vector') {
        if (
            !Array.isArray(input.matchTypes) ||
            input.matchTypes.length !== 1 ||
            input.matchTypes[0] !== 'vector' ||
            Object.hasOwn(input, 'fullText')
        )
            throw new Error('Invalid vector search');
        return {
            valueType: 'vector',
            matchTypes: ['vector'],
            vector: parseVectorPolicy(input.vector),
        };
    }
    if (Object.hasOwn(input, 'vector')) throw new Error('Mismatched vector policy');
    const valueType = input.valueType;
    if (valueType !== 'string' && valueType !== 'integer' && valueType !== 'number')
        throw new Error('Invalid value type');
    const matchTypes = list(input.matchTypes, (value) => {
        const parsed = match(value);
        if (parsed === 'vector') throw new Error('Mismatched vector search');
        return parsed;
    });
    if (valueType !== 'string' && (matchTypes.length !== 1 || matchTypes[0] !== 'equals'))
        throw new Error('Invalid search');
    if (matchTypes.includes('full_text') !== Object.hasOwn(input, 'fullText'))
        throw new Error('Mismatched full-text policy');
    return {
        matchTypes,
        valueType,
        ...(Object.hasOwn(input, 'fullText') ? { fullText: parseFullText(input.fullText) } : {}),
    };
}
function parseOrder(value: unknown): OrderDefinition | null {
    if (value === null) return null;
    const input = object(value);
    exact(input, ['sortTypes', 'directions'], ['locale']);
    const sortTypes = list(input.sortTypes, sort);
    if (Object.hasOwn(input, 'locale') && !sortTypes.includes('locale'))
        throw new Error('Mismatched locale policy');
    return {
        sortTypes,
        directions: list(input.directions, direction),
        ...(Object.hasOwn(input, 'locale') ? { locale: parseLocale(input.locale) } : {}),
    };
}
function parseFilters(value: unknown): FieldDefinition['filters'] {
    if (value === null) return null;
    const result = dictionary<FilterDefinition>();
    for (const [key, raw] of Object.entries(object(value))) {
        identifier(key);
        const input = object(raw);
        if (input.multiple === false) {
            exact(input, ['values', 'multiple']);
            result[key] = { values: list(input.values, scalar), multiple: false };
        } else {
            exact(input, ['values', 'multiple', 'empty']);
            if (input.multiple !== true || (input.empty !== 'clear' && input.empty !== 'reject'))
                throw new Error('Invalid filter');
            result[key] = {
                values: list(input.values, scalar),
                multiple: true,
                empty: input.empty,
            };
        }
    }
    if (!Object.keys(result).length) throw new Error('Empty filters');
    return result;
}
export function parseDefinition(value: unknown): AdapterDefinition {
    const input = object(value);
    exact(input, ['fields', 'global', 'paging']);
    const fields = dictionary<FieldDefinition>();
    for (const [key, raw] of Object.entries(object(input.fields))) {
        identifier(key);
        const field = object(raw);
        exact(field, ['search', 'order', 'filters']);
        fields[key] = {
            search: parseSearch(field.search),
            order: parseOrder(field.order),
            filters: parseFilters(field.filters),
        };
    }
    const global = object(input.global);
    exact(
        global,
        ['multi_order', 'multi_order_limit', 'searchMatch', 'filters', 'search', 'order'],
        ['stability'],
    );
    const search = object(global.searchMatch);
    exact(search, ['default', 'allowed']);
    const searchDefault = searchMatch(search.default),
        allowed = list(search.allowed, searchMatch);
    if (!allowed.includes(searchDefault) || typeof global.multi_order !== 'boolean')
        throw new Error('Invalid global capabilities');
    const limit = positive(global.multi_order_limit);
    if (!global.multi_order && limit !== 1) throw new Error('Invalid order limit');
    let globalSearch: GlobalSearchDefinition | null = null;
    if (global.search !== null) {
        const raw = object(global.search);
        exact(raw, ['valueType', 'targets']);
        if (raw.valueType !== 'string') throw new Error('Invalid global search');
        const targets = dictionary<GlobalSearchDefinition['targets'][string]>();
        for (const [id, value] of Object.entries(object(raw.targets))) {
            identifier(id);
            const target = object(value);
            exact(target, ['valueType', 'matchType'], ['fullText']);
            const parsed = parseSearch({
                valueType: target.valueType,
                matchTypes: [target.matchType],
                ...(Object.hasOwn(target, 'fullText') ? { fullText: target.fullText } : {}),
            });
            if (!parsed || parsed.valueType === 'vector') throw new Error('Invalid target');
            const targetMatch = match(target.matchType);
            if (targetMatch === 'vector') throw new Error('Invalid global vector search');
            targets[id] = {
                valueType: parsed.valueType,
                matchType: targetMatch,
                ...(parsed.fullText ? { fullText: parsed.fullText } : {}),
            };
        }
        if (!Object.keys(targets).length) throw new Error('Empty targets');
        globalSearch = { valueType: 'string', targets };
    }
    let globalOrder: GlobalOrderDefinition | null = null;
    if (global.order !== null) {
        const raw = object(global.order);
        exact(raw, ['presets', 'pagingModes']);
        if (
            !Array.isArray(raw.presets) ||
            !raw.presets.length ||
            !Array.isArray(raw.pagingModes) ||
            raw.pagingModes.length !== 1 ||
            raw.pagingModes[0] !== 'offset'
        )
            throw new Error('Invalid presets');
        const presets = raw.presets.map((value: unknown) => {
            const preset = object(value);
            exact(preset, ['id', 'label']);
            if (typeof preset.id !== 'string' || typeof preset.label !== 'string' || !preset.label)
                throw new Error('Invalid preset');
            identifier(preset.id);
            return { id: preset.id, label: preset.label };
        });
        if (new Set(presets.map((preset) => preset.id)).size !== presets.length)
            throw new Error('Duplicate presets');
        globalOrder = { presets, pagingModes: ['offset'] };
    }
    let stability: StabilityDefinition | undefined;
    if (global.stability !== undefined) {
        const raw = object(global.stability);
        exact(raw, ['prefix', 'identity']);
        const labels = (value: unknown): StabilityTerm[] => {
            if (!Array.isArray(value)) throw new Error('Invalid labels');
            return value.map((label: unknown) => {
                const term = object(label);
                exact(term, ['id', 'direction', 'sortType']);
                if (typeof term.id !== 'string' || !term.id) throw new Error('Invalid label');
                return {
                    id: term.id,
                    direction: direction(term.direction),
                    sortType: sort(term.sortType),
                };
            });
        };
        stability = { prefix: labels(raw.prefix), identity: labels(raw.identity) };
    }
    const paging = object(input.paging);
    const mode = paging.mode;
    if (mode !== 'offset' && mode !== 'simple' && mode !== 'cursor')
        throw new Error('Invalid paging');
    exact(paging, [
        'mode',
        'per_page',
        'per_page_options',
        ...(mode === 'offset' ? [] : ['counts']),
        ...(mode === 'cursor' ? ['expires_after'] : []),
    ]);
    if (mode !== 'offset' && paging.counts !== 'unknown' && paging.counts !== 'exact')
        throw new Error('Invalid counts policy');
    if (mode === 'cursor' && paging.expires_after !== 900)
        throw new Error('Invalid cursor lifetime');
    if (
        mode === 'cursor' &&
        (Object.values(fields).some((field) => field?.order?.sortTypes.includes('locale')) ||
            [...(stability?.prefix ?? []), ...(stability?.identity ?? [])].some(
                (term) => term.sortType === 'locale',
            ))
    )
        throw new Error('Unsupported cursor locale');
    const per_page = positive(paging.per_page),
        per_page_options = list(paging.per_page_options, positive);
    if (!per_page_options.includes(per_page)) throw new Error('Invalid sizes');
    return freeze({
        fields,
        global: {
            multi_order: global.multi_order,
            multi_order_limit: limit,
            searchMatch: { default: searchDefault, allowed },
            search: globalSearch,
            filters: parseFilters(global.filters),
            order: globalOrder,
            ...(stability ? { stability } : {}),
        },
        paging:
            mode === 'offset'
                ? { mode, per_page, per_page_options }
                : mode === 'simple'
                  ? {
                        mode,
                        per_page,
                        per_page_options,
                        counts: paging.counts === 'exact' ? 'exact' : 'unknown',
                    }
                  : {
                        mode,
                        per_page,
                        per_page_options,
                        counts: paging.counts === 'exact' ? 'exact' : 'unknown',
                        expires_after: 900,
                    },
    });
}
export function parseRequestedV2(
    value: unknown,
    definition?: AdapterDefinition,
): SourceRequestedStateV2 {
    const input = object(value);
    exact(input, ['version', 'fields', 'global', 'searchMatch', 'order', 'paging']);
    if (input.version !== 2) throw new Error('Invalid state');
    const grouping = searchMatch(input.searchMatch);
    if (definition && !definition.global.searchMatch.allowed.includes(grouping))
        throw new Error('Unavailable grouping');
    const globalInput = object(input.global);
    exact(globalInput, [], ['search', 'filters']);
    const global: RequestedStateV2['global'] = parseGlobalSelection(globalInput, definition);
    const paging = object(input.paging);
    const position = parsePosition(paging);
    const per_page = position.per_page;
    if (definition && position.mode !== definition.paging.mode) throw new Error('Mismatched mode');
    if (definition && !definition.paging.per_page_options.includes(per_page))
        throw new Error('Unavailable size');
    const fields = dictionary<FieldSelection>();
    for (const [name, raw] of Object.entries(object(input.fields))) {
        identifier(name);
        const field = object(raw);
        exact(field, [], ['search', 'filters']);
        const capability = definition?.fields[name];
        if (definition && !capability) throw new Error('Unavailable field');
        let search: SearchSelection | undefined;
        if (Object.hasOwn(field, 'search')) {
            const selection = object(field.search);
            exact(selection, ['value', 'matchType']);
            const matchType = match(selection.matchType);
            if (matchType === 'vector') {
                if (capability && capability.search?.valueType !== 'vector')
                    throw new Error('Unavailable vector search');
                const value = parseVector(selection.value, capability?.search?.vector);
                search = { value, matchType };
            } else {
                const value = scalar(selection.value);
                if (
                    (typeof value !== 'string' && typeof value !== 'number') ||
                    (capability &&
                        (!capability.search ||
                            !capability.search.matchTypes.some(
                                (available) => available === matchType,
                            ) ||
                            !validSearchValue(value, capability.search.valueType)))
                )
                    throw new Error('Invalid search');
                if (matchType === 'full_text' && !validFullTextInput(value))
                    throw new Error('Invalid full-text input');
                if (value !== '') search = { value, matchType };
            }
        }
        const filters = dictionary<FilterSelection>();
        if (Object.hasOwn(field, 'filters'))
            for (const [id, rawFilter] of Object.entries(object(field.filters))) {
                identifier(id);
                const selection = object(rawFilter);
                exact(selection, ['value']);
                const available = capability?.filters?.[id];
                if (definition && !available) throw new Error('Unavailable filter');
                const value = Array.isArray(selection.value)
                    ? selection.value.map(scalar)
                    : scalar(selection.value);
                if (available) {
                    if (available.multiple !== Array.isArray(value))
                        throw new Error('Invalid multiplicity');
                    const values = Array.isArray(value) ? value : [value];
                    if (
                        (!values.length && available.multiple && available.empty === 'reject') ||
                        values.some((item) => !available.values.includes(item))
                    )
                        throw new Error('Unavailable value');
                    if (!values.length) continue;
                }
                filters[id] = { value: Array.isArray(value) ? [...new Set(value)] : value };
            }
        if (search || Object.keys(filters).length)
            fields[name] = {
                ...(search ? { search } : {}),
                ...(Object.keys(filters).length ? { filters } : {}),
            };
    }
    const orderInput = object(input.order);
    let order: RequestedStateV2['order'];
    if (orderInput.kind === 'default') {
        exact(orderInput, ['kind']);
        order = { kind: 'default' };
    } else if (orderInput.kind === 'preset') {
        exact(orderInput, ['kind', 'id']);
        if (typeof orderInput.id !== 'string') throw new Error('Invalid preset');
        identifier(orderInput.id);
        if (
            definition &&
            !definition.global.order?.presets.some((preset) => preset.id === orderInput.id)
        )
            throw new Error('Unavailable preset');
        if (position.mode !== 'offset') throw new Error('Preset paging unavailable');
        order = { kind: 'preset', id: orderInput.id };
    } else {
        exact(orderInput, ['kind', 'terms']);
        if (
            orderInput.kind !== 'fields' ||
            !Array.isArray(orderInput.terms) ||
            !orderInput.terms.length
        )
            throw new Error('Invalid order');
        const terms = orderInput.terms.map((raw: unknown) => {
            const term = object(raw);
            exact(term, ['field', 'direction', 'sortType']);
            if (typeof term.field !== 'string') throw new Error('Invalid field');
            identifier(term.field);
            const selected = {
                field: term.field,
                direction: direction(term.direction),
                sortType: sort(term.sortType),
            };
            const available = definition?.fields[selected.field]?.order;
            if (
                definition &&
                (!available ||
                    !available.directions.includes(selected.direction) ||
                    !available.sortTypes.includes(selected.sortType))
            )
                throw new Error('Unavailable order');
            if (position.mode === 'cursor' && selected.sortType !== 'native')
                throw new Error('Unsupported cursor sort');
            return selected;
        });
        if (
            new Set(terms.map((term) => term.field)).size !== terms.length ||
            (definition &&
                (terms.length > definition.global.multi_order_limit ||
                    (!definition.global.multi_order && terms.length > 1)))
        )
            throw new Error('Invalid order limit');
        order = { kind: 'fields', terms };
    }
    return freeze({
        version: 2,
        fields,
        global,
        searchMatch: grouping,
        order,
        paging: position,
    });
}
function validFullTextInput(value: string | number): boolean {
    return (
        typeof value === 'string' &&
        !value.includes('\0') &&
        !/[\uD800-\uDFFF]/u.test(value) &&
        new TextEncoder().encode(value).length <= 512
    );
}
function validSearchValue(value: string | number, type: SearchDefinition['valueType']): boolean {
    return type === 'string'
        ? typeof value === 'string'
        : typeof value === 'number' && (type === 'number' || Number.isSafeInteger(value));
}
function searchMatch(value: unknown): SearchMatch {
    if (value === 'all' || value === 'any') return value;
    throw new Error('Invalid grouping');
}
function parseGlobalSelection(
    input: Record<string, unknown>,
    definition?: AdapterDefinition,
): RequestedStateV2['global'] {
    let search: { value: string } | undefined;
    if (Object.hasOwn(input, 'search')) {
        const raw = object(input.search);
        exact(raw, ['value']);
        if (typeof raw.value !== 'string' || (definition && !definition.global.search))
            throw new Error('Unavailable global search');
        if (
            definition &&
            Object.values(definition.global.search?.targets ?? {}).some(
                (target) => target.matchType === 'full_text',
            ) &&
            !validFullTextInput(raw.value)
        )
            throw new Error('Invalid full-text input');
        if (raw.value !== '') search = { value: raw.value };
    }
    const filters = dictionary<FilterSelection>();
    if (Object.hasOwn(input, 'filters'))
        for (const [id, raw] of Object.entries(object(input.filters))) {
            identifier(id);
            const selection = object(raw);
            exact(selection, ['value']);
            const available = definition?.global.filters?.[id];
            if (definition && !available) throw new Error('Unavailable filter');
            const value = Array.isArray(selection.value)
                ? [...new Set(selection.value.map(scalar))]
                : scalar(selection.value);
            if (available) {
                if (available.multiple !== Array.isArray(value))
                    throw new Error('Invalid multiplicity');
                const values = Array.isArray(value) ? value : [value];
                if (
                    values.some((item) => !available.values.includes(item)) ||
                    (!values.length && available.multiple && available.empty === 'reject')
                )
                    throw new Error('Unavailable value');
                if (!values.length) continue;
            }
            filters[id] = { value };
        }
    return { ...(search ? { search } : {}), ...(Object.keys(filters).length ? { filters } : {}) };
}
function sameGlobalSearch(
    left: GlobalSearchDefinition,
    right: GlobalSearchDefinition | null,
): boolean {
    return (
        right !== null &&
        Object.keys(left.targets).length === Object.keys(right.targets).length &&
        Object.entries(left.targets).every(([id, target]) => {
            const source = right.targets[id];
            return (
                source?.valueType === target.valueType &&
                source.matchType === target.matchType &&
                JSON.stringify(source.fullText) === JSON.stringify(target.fullText)
            );
        })
    );
}
export function assertNarrowing(original: AdapterDefinition, narrowed: AdapterDefinition): void {
    if (
        (narrowed.global.multi_order && !original.global.multi_order) ||
        narrowed.global.multi_order_limit > original.global.multi_order_limit ||
        narrowed.global.searchMatch.allowed.some(
            (mode) => !original.global.searchMatch.allowed.includes(mode),
        ) ||
        narrowed.global.searchMatch.default !== original.global.searchMatch.default ||
        (narrowed.global.search !== null &&
            !sameGlobalSearch(narrowed.global.search, original.global.search)) ||
        (narrowed.global.order?.presets.some(
            (preset) =>
                !original.global.order?.presets.some(
                    (source) => source.id === preset.id && source.label === preset.label,
                ),
        ) ??
            false) ||
        JSON.stringify(narrowed.global.stability) !== JSON.stringify(original.global.stability) ||
        original.paging.mode !== narrowed.paging.mode ||
        ('counts' in original.paging ? original.paging.counts : null) !==
            ('counts' in narrowed.paging ? narrowed.paging.counts : null) ||
        ('expires_after' in original.paging ? original.paging.expires_after : null) !==
            ('expires_after' in narrowed.paging ? narrowed.paging.expires_after : null) ||
        original.paging.per_page !== narrowed.paging.per_page ||
        narrowed.paging.per_page_options.some(
            (size) => !original.paging.per_page_options.includes(size),
        )
    )
        throw new Error('Expanded definition');
    for (const [id, filter] of Object.entries(narrowed.global.filters ?? {})) {
        const source = original.global.filters?.[id];
        if (
            filter &&
            (!source ||
                source.multiple !== filter.multiple ||
                (filter.multiple && (!source.multiple || source.empty !== filter.empty)) ||
                filter.values.some((value) => !source.values.includes(value)))
        )
            throw new Error('Expanded global filter');
    }
    for (const [name, field] of Object.entries(narrowed.fields)) {
        if (!field) continue;
        const source = original.fields[name];
        if (!source) throw new Error('Expanded field');
        if (
            field.search &&
            (!source.search ||
                field.search.valueType !== source.search.valueType ||
                field.search.matchTypes.some(
                    (value) => !source.search?.matchTypes.some((available) => available === value),
                ) ||
                (field.search.matchTypes.some((matchType) => matchType === 'full_text') &&
                    JSON.stringify(field.search.fullText) !==
                        JSON.stringify(source.search.fullText)) ||
                JSON.stringify(field.search.vector) !== JSON.stringify(source.search.vector))
        )
            throw new Error('Expanded search');
        if (
            field.order &&
            (!source.order ||
                field.order.sortTypes.some((value) => !source.order?.sortTypes.includes(value)) ||
                field.order.directions.some((value) => !source.order?.directions.includes(value)) ||
                (field.order.sortTypes.includes('locale') &&
                    JSON.stringify(field.order.locale) !== JSON.stringify(source.order.locale)))
        )
            throw new Error('Expanded order');
        for (const [id, filter] of Object.entries(field.filters ?? {})) {
            if (!filter) continue;
            const sourceFilter = source.filters?.[id];
            if (
                !sourceFilter ||
                sourceFilter.multiple !== filter.multiple ||
                (filter.multiple &&
                    (!sourceFilter.multiple || sourceFilter.empty !== filter.empty)) ||
                filter.values.some((value) => !sourceFilter.values.includes(value))
            )
                throw new Error('Expanded filter');
        }
    }
}
function parseBrowserState(value: unknown): BrowserStatePolicy {
    const policy = object(value);
    exact(policy, ['key', 'version', 'expiration']);
    if (
        typeof policy.key !== 'string' ||
        policy.key.trim() === '' ||
        typeof policy.version !== 'string' ||
        !/^[a-f0-9]{64}$/.test(policy.version)
    )
        throw new Error('Invalid browser state policy');
    const deadline = object(policy.expiration);
    exact(deadline, ['at' in deadline ? 'at' : 'minutes']);
    return {
        key: policy.key,
        version: policy.version,
        expiration:
            'at' in deadline
                ? { at: positive(deadline.at) }
                : { minutes: positive(deadline.minutes) },
    };
}

export function parsePageV2(value: unknown): PageV2 {
    const input = object(value);
    exact(
        input,
        ['version', 'data', 'requested', 'state', 'paging', 'capabilities', 'metadata'],
        ['actions', 'browserState'],
    );
    const browserState = Object.hasOwn(input, 'browserState')
        ? { browserState: parseBrowserState(input.browserState) }
        : {};
    if (input.version !== 2) throw new Error('Invalid version');
    const definition = parseDefinition(input.capabilities),
        requested = parseRequestedV2(input.requested, definition);
    const state = object(input.state);
    exact(state, ['order', 'paging']);
    const order = object(state.order);
    let acceptedOrder: AcceptedStateV2['order'];
    if (order.kind === 'source') {
        exact(order, ['kind']);
        if (requested.order.kind !== 'default') throw new Error('Mismatched order');
        acceptedOrder = { kind: 'source' };
    } else if (order.kind === 'preset') {
        exact(order, ['kind', 'id', 'identity', 'opaque']);
        if (
            requested.order.kind !== 'preset' ||
            order.id !== requested.order.id ||
            order.identity !== `preset:${requested.order.id}` ||
            order.opaque !== true
        )
            throw new Error('Mismatched preset');
        acceptedOrder = {
            kind: 'preset',
            id: requested.order.id,
            identity: order.identity,
            opaque: true,
        };
    } else {
        exact(order, ['kind', 'terms']);
        if (order.kind !== 'terms' || !Array.isArray(order.terms))
            throw new Error('Mismatched order');
        const terms = order.terms.map(
            (raw: unknown): OrderSelection & { field: string; role?: 'prefix' | 'tie' } => {
                const term = object(raw);
                exact(term, ['field', 'direction', 'sortType'], ['role']);
                if (typeof term.field !== 'string') throw new Error('Invalid term');
                if (
                    term.role !== undefined &&
                    (!definition.global.stability ||
                        (term.role !== 'prefix' && term.role !== 'tie') ||
                        !(
                            term.role === 'prefix'
                                ? definition.global.stability.prefix
                                : definition.global.stability.identity
                        ).some(
                            (label) =>
                                label.id === term.field &&
                                label.direction === term.direction &&
                                label.sortType === term.sortType,
                        ))
                )
                    throw new Error('Invalid stability role');
                return {
                    field: term.field,
                    direction: direction(term.direction),
                    sortType: sort(term.sortType),
                    ...(term.role === 'prefix' || term.role === 'tie' ? { role: term.role } : {}),
                };
            },
        );
        const selected = terms.filter((term) => !term.role);
        const prefix = definition.global.stability?.prefix ?? [],
            identities = definition.global.stability?.identity ?? [];
        const expectedPrefix = prefix.map((term) => ({
            field: term.id,
            direction: term.direction,
            sortType: term.sortType,
            role: 'prefix',
        }));
        const actualPrefix = terms.filter((term) => term.role === 'prefix');
        const ties = terms.filter((term) => term.role === 'tie');
        const tieIndices = ties.map((term) =>
            identities.findIndex((label) => label.id === term.field),
        );
        if (
            JSON.stringify(actualPrefix) !== JSON.stringify(expectedPrefix) ||
            terms.some((term, index) =>
                index < prefix.length
                    ? term.role !== 'prefix'
                    : index < prefix.length + selected.length
                      ? term.role !== undefined
                      : term.role !== 'tie',
            ) ||
            tieIndices.some(
                (index, position) => position > 0 && index <= (tieIndices[position - 1] ?? -1),
            )
        )
            throw new Error('Mismatched stability order');
        if (
            JSON.stringify(selected) !==
            JSON.stringify(requested.order.kind === 'fields' ? requested.order.terms : [])
        )
            throw new Error('Mismatched order');
        acceptedOrder = { kind: 'terms', terms };
    }
    const position = parsePosition(state.paging);
    if (JSON.stringify(position) !== JSON.stringify(requested.paging))
        throw new Error('Mismatched paging');
    const data = parseRows(input.data);
    const metadata = parseMetadata(input.metadata, data.length);
    if (definition.paging.mode !== 'offset') {
        const paging = parseAdvancedPaging(
            input.paging,
            requested.paging,
            definition.paging.counts,
        );
        if (!Array.isArray(input.data) || input.data.length > paging.per_page)
            throw new Error('Invalid rows');
        validateJson(input.data);
        if (
            paging.mode === 'cursor' &&
            input.data.length === 0 &&
            (paging.has_previous || paging.has_next)
        )
            throw new Error('Invalid empty cursor window');
        return freeze({
            ...browserState,
            data: input.data,
            requested,
            state: { order: acceptedOrder, paging: requested.paging },
            paging,
            options: definition.paging.per_page_options,
            definition,
            metadata,
            ...(Object.hasOwn(input, 'actions') ? { actions: parseActions(input.actions) } : {}),
        });
    }
    const { metadata: _metadata, browserState: _policy, ...base } = input;
    const legacy = {
        ...base,
        version: 1,
        requested: {
            version: 1,
            fields: {},
            global: {},
            searchMatch: 'all',
            order: { kind: 'default' },
            paging: requested.paging,
        },
        state: { order: { kind: 'source' }, paging: requested.paging },
        capabilities: {
            fields: {},
            global: {
                multi_order: false,
                multi_order_limit: 1,
                searchMatch: { default: 'all', allowed: ['all'] },
                search: null,
                filters: null,
                order: null,
            },
            paging: definition.paging,
        },
    };
    return freeze({
        ...parseLegacy(legacy),
        ...browserState,
        requested,
        state: { order: acceptedOrder, paging: requested.paging },
        definition,
        metadata,
    });
}
function cursor(value: unknown): string | null {
    if (value === null || (typeof value === 'string' && value.length > 0 && value.length <= 16384))
        return value;
    throw new Error('Invalid cursor');
}
export function parsePosition(value: unknown): PagingPosition {
    const input = object(value);
    if (input.mode === 'cursor') {
        exact(input, ['mode', 'cursor', 'per_page']);
        return { mode: 'cursor', cursor: cursor(input.cursor), per_page: positive(input.per_page) };
    }
    exact(input, ['mode', 'current', 'per_page']);
    if (input.mode !== 'offset' && input.mode !== 'simple') throw new Error('Invalid paging');
    const current = positive(input.current),
        per_page = positive(input.per_page);
    if (input.mode === 'simple' && !Number.isSafeInteger((current - 1) * per_page + per_page + 1))
        throw new Error('Unsafe paging');
    return { mode: input.mode, current, per_page };
}
function parseAdvancedPaging(
    value: unknown,
    position: PagingPosition,
    counts: 'unknown' | 'exact',
): AdvancedResultPaging {
    const input = object(value);
    exact(input, [
        'mode',
        'current',
        'per_page',
        'pages',
        'total',
        'filtered',
        'previous_cursor',
        'next_cursor',
        'has_previous',
        'has_next',
        ...(position.mode === 'cursor' ? ['cursor'] : []),
    ]);
    if (
        position.mode === 'offset' ||
        input.mode !== position.mode ||
        input.per_page !== position.per_page ||
        input.pages !== null ||
        typeof input.has_previous !== 'boolean' ||
        typeof input.has_next !== 'boolean'
    )
        throw new Error('Mismatched paging');
    let total: number | null = null,
        filtered: number | null = null;
    if (counts === 'exact') {
        total = nonnegative(input.total);
        filtered = nonnegative(input.filtered);
        if (filtered > total) throw new Error('Invalid counts');
    } else if (input.total !== null || input.filtered !== null) throw new Error('Invalid counts');
    const common = {
        pages: null,
        total,
        filtered,
        has_previous: input.has_previous,
        has_next: input.has_next,
    } satisfies Pick<
        AdvancedResultPaging,
        'pages' | 'total' | 'filtered' | 'has_previous' | 'has_next'
    >;
    if (position.mode === 'simple') {
        if (
            input.current !== position.current ||
            input.previous_cursor !== null ||
            input.next_cursor !== null ||
            input.has_previous !== position.current > 1
        )
            throw new Error('Invalid simple paging');
        return { ...common, ...position, previous_cursor: null, next_cursor: null };
    }
    const previous_cursor = cursor(input.previous_cursor),
        next_cursor = cursor(input.next_cursor);
    if (
        input.current !== null ||
        input.cursor !== position.cursor ||
        input.has_previous !== (previous_cursor !== null) ||
        input.has_next !== (next_cursor !== null)
    )
        throw new Error('Invalid cursor paging');
    return { ...common, ...position, current: null, previous_cursor, next_cursor };
}
function nonnegative(value: unknown): number {
    if (value === 0) return 0;
    return positive(value);
}
export type DefinitionDecoder<D extends AdapterDefinition = AdapterDefinition> = (
    definition: ReadonlyDeep<AdapterDefinition>,
) => D;
