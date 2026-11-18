import { freeze, dictionary, object } from './protocol.js';
import {
    parseRequestedV2,
    exact,
    type AdapterDefinition,
    type FilterSelection,
    type OrderSelection,
    type SourceRequestedStateV2 as RequestedStateV2,
    type SearchSelection,
} from './protocol-v2.js';
export type StateOptions = { readonly page?: 'first' | 'preserve' };
export type StateCommand =
    | { readonly kind: 'replace'; readonly value: unknown }
    | { readonly kind: 'page'; readonly value: number }
    | { readonly kind: 'cursor'; readonly value: string | null }
    | { readonly kind: 'size'; readonly value: number }
    | {
          readonly kind: 'globalSearch';
          readonly value: { readonly value: string } | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'globalFilter';
          readonly id: string;
          readonly value: FilterSelection | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'preset';
          readonly value: { readonly id: string } | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'grouping';
          readonly value: 'all' | 'any';
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'search';
          readonly field: string;
          readonly value: SearchSelection | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'filter';
          readonly field: string;
          readonly id: string;
          readonly value: FilterSelection | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      }
    | {
          readonly kind: 'order';
          readonly field: string;
          readonly value: (OrderSelection & { readonly multiSort?: boolean }) | null;
          readonly options?: StateOptions;
          readonly clear?: true;
      };
export function stateKey(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(stateKey).join(',')}]`;
    if (value !== null && typeof value === 'object')
        return `{${Object.keys(value)
            .sort()
            .map((key) => `${JSON.stringify(key)}:${stateKey(Reflect.get(value, key))}`)
            .join(',')}}`;
    return JSON.stringify(value) ?? 'undefined';
}
export function reduceRequested(
    current: RequestedStateV2,
    command: StateCommand,
    definition: AdapterDefinition,
    pages: number | null,
): RequestedStateV2 {
    if (command.kind === 'replace') return parseRequestedV2(command.value, definition);
    if (command.kind === 'cursor') {
        if (current.paging.mode !== 'cursor') throw new Error('Invalid cursor');
        return parseRequestedV2(
            { ...current, paging: { ...current.paging, cursor: command.value } },
            definition,
        );
    }
    if (command.kind === 'page') {
        if (
            !Number.isSafeInteger(command.value) ||
            command.value < 1 ||
            current.paging.mode === 'cursor' ||
            (pages !== null && command.value > pages)
        )
            throw new Error('Invalid page');
        return parseRequestedV2(
            { ...current, paging: { ...current.paging, current: command.value } },
            definition,
        );
    }
    const first =
        current.paging.mode === 'cursor'
            ? { ...current.paging, cursor: null }
            : { ...current.paging, current: 1 };
    if (command.kind === 'size')
        return parseRequestedV2(
            {
                ...current,
                paging: {
                    ...(current.paging.per_page === command.value ? current.paging : first),
                    per_page: command.value,
                },
            },
            definition,
        );
    if (command.options !== undefined) exact(object(command.options), [], ['page']);
    if (!command.clear && (command.value === null || command.value === undefined))
        throw new Error('Invalid selection');
    if (
        command.options &&
        command.options.page !== undefined &&
        command.options.page !== 'first' &&
        command.options.page !== 'preserve'
    )
        throw new Error('Invalid page policy');
    if (command.options?.page === 'preserve' && current.paging.mode !== 'offset')
        throw new Error('Page preservation unavailable');
    const paging = command.options?.page === 'preserve' ? current.paging : first;
    if (command.kind === 'preset' && current.paging.mode !== 'offset')
        throw new Error('Preset paging unavailable');
    if (command.kind === 'order') {
        if (command.value !== null)
            exact(object(command.value), ['direction', 'sortType'], ['multiSort']);
        const active =
            current.order.kind === 'fields' &&
            current.order.terms.some((term) => term.field === command.field);
        if (!command.value && !active) return current;
        if (
            command.value?.multiSort &&
            (!definition.global.multi_order || current.order.kind === 'preset')
        )
            throw new Error('Multiple order unavailable');
        const existing = current.order.kind === 'fields' ? current.order.terms : [];
        let terms = command.value?.multiSort ? [...existing] : [];
        if (command.value) {
            const selected = {
                field: command.field,
                direction: command.value.direction,
                sortType: command.value.sortType,
            };
            const index = terms.findIndex((term) => term.field === command.field);
            if (index < 0) terms.push(selected);
            else terms[index] = selected;
        } else terms = existing.filter((term) => term.field !== command.field);
        const candidate = parseRequestedV2(
            {
                ...current,
                order: terms.length ? { kind: 'fields', terms } : { kind: 'default' },
            },
            definition,
        );
        if (stateKey(candidate) === stateKey(current)) return current;
        return freeze({ ...candidate, paging });
    }
    if (
        command.kind === 'globalSearch' ||
        command.kind === 'globalFilter' ||
        command.kind === 'preset' ||
        command.kind === 'grouping'
    ) {
        let global = current.global,
            order = current.order,
            searchMatch = current.searchMatch;
        if (command.kind === 'grouping') searchMatch = command.value;
        else if (command.kind === 'preset') {
            if (command.value) exact(object(command.value), ['id']);
            if (!command.value && order.kind !== 'preset') return current;
            order = command.value ? { kind: 'preset', id: command.value.id } : { kind: 'default' };
        } else if (command.kind === 'globalSearch') {
            if (command.value) exact(object(command.value), ['value']);
            const { search: _search, ...rest } = global;
            global = command.value ? { ...rest, search: command.value } : rest;
        } else {
            const filters = Object.assign(dictionary<FilterSelection>(), global.filters);
            if (command.value) filters[command.id] = command.value;
            else delete filters[command.id];
            const { filters: _filters, ...rest } = global;
            global = Object.keys(filters).length ? { ...rest, filters } : rest;
        }
        const candidate = parseRequestedV2({ ...current, global, order, searchMatch }, definition);
        return stateKey(candidate) === stateKey(current)
            ? current
            : freeze({ ...candidate, paging });
    }
    const fields = Object.assign(
            dictionary<import('./protocol-v2.js').FieldSelection>(),
            current.fields,
        ),
        existing = fields[command.field] ?? {};
    if (command.kind === 'search') {
        if (!command.value && !existing.search) return current;
        const { search: _search, ...rest } = existing;
        fields[command.field] = command.value ? { ...rest, search: command.value } : rest;
    } else {
        if (!command.value && !existing.filters?.[command.id]) return current;
        const filters = Object.assign(dictionary<FilterSelection>(), existing.filters);
        if (command.value) filters[command.id] = command.value;
        else delete filters[command.id];
        const { filters: _filters, ...rest } = existing;
        fields[command.field] = Object.keys(filters).length ? { ...rest, filters } : rest;
    }
    const candidate = parseRequestedV2({ ...current, fields, paging: current.paging }, definition);
    if (stateKey(candidate) === stateKey(current)) return current;
    return freeze({ ...candidate, paging });
}
