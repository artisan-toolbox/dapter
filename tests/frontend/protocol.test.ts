import { describe, expect, it } from 'vite-plus/test';
import corpus from '../../resources/contracts/read-v1.json';
import { parseFailure, parsePage } from '../../resources/js/protocol';

describe('current read protocol corpus', () => {
    it('uses the same complete responses for raw route cases and parser examples', () => {
        for (const request of corpus.requests) {
            const examples = request.status === 200 ? corpus.successes : corpus.failures;
            const example = examples.find(({ id }) => id === request.id);
            expect(example?.response, request.id).toEqual(request.response);
        }
    });
    it.each(corpus.successes)('accepts $id with its complete JSON values', ({ response }) => {
        const accepted = parsePage(response);
        expect(accepted.data).toEqual(response.data);
        expect(accepted.requested).toEqual(response.requested);
        expect(accepted.state).toEqual(response.state);
        expect(accepted.paging).toEqual(response.paging);
        expect(accepted.options).toEqual(response.capabilities.paging.per_page_options);
        expect(Object.isFrozen(accepted.data)).toBe(true);
    });
    it.each(corpus.failures)('accepts $id without inferring kind from status', ({ response }) => {
        expect(parseFailure(response)).toEqual(response.error);
    });
    it.each(corpus.invalid_successes)('rejects success control $id', ({ response }) => {
        expect(() => parsePage(response)).toThrow();
    });
    it.each(corpus.invalid_failures)('rejects failure control $id', ({ response }) => {
        expect(parseFailure(response)).toBeNull();
    });
});

import {
    assertNarrowing,
    parseDefinition,
    parsePageV2,
    parseRequestedV2,
} from '../../resources/js/protocol-v2';
function definitionV2() {
    return {
        fields: {
            name: {
                search: { matchTypes: ['contains', 'equals'], valueType: 'string' },
                order: { sortTypes: ['native'], directions: ['asc', 'desc'] },
                filters: { catalog: { values: ['Notebook', 'Pencil'], multiple: false } },
            },
            quantity: {
                search: { matchTypes: ['equals'], valueType: 'integer' },
                order: null,
                filters: { count: { values: [null, 0, 1], multiple: true, empty: 'clear' } },
            },
            active: {
                search: null,
                order: null,
                filters: { enabled: { values: [false, true], multiple: false } },
            },
        },
        global: {
            multi_order: false,
            multi_order_limit: 1,
            searchMatch: { default: 'all', allowed: ['all'] },
            filters: null,
            search: null,
            order: null,
        },
        paging: { mode: 'offset', per_page: 2, per_page_options: [2, 3] },
    };
}
function requestedV2() {
    return {
        version: 2,
        fields: {},
        global: {},
        searchMatch: 'all',
        order: { kind: 'default' },
        paging: { mode: 'offset', current: 1, per_page: 2 },
    };
}
describe('explicit read protocol v2', () => {
    it('preserves effective source order and detached immutable metadata', () => {
        const legacy = corpus.successes[0];
        if (!legacy) throw new Error('Missing corpus response');
        const requested = { ...requestedV2(), paging: legacy.response.requested.paging };
        const capabilities = { ...definitionV2(), paging: legacy.response.capabilities.paging };
        const response = {
            ...legacy.response,
            version: 2,
            requested,
            capabilities,
            metadata: { records: { mode: 'unidentified' }, channel: null },
        };
        const accepted = parsePageV2(response);
        expect(accepted.state).toEqual(response.state);
        expect(accepted.definition).toEqual(capabilities);
        expect(accepted.definition).not.toBe(capabilities);
        expect(Object.isFrozen(accepted.definition.fields)).toBe(true);
        expect(Object.isFrozen(accepted.metadata.records)).toBe(true);
    });
    it('keeps typed null, false, zero, and string selections distinct', () => {
        const definition = parseDefinition(definitionV2());
        const requested = parseRequestedV2(
            {
                ...requestedV2(),
                fields: {
                    quantity: { filters: { count: { value: [null, 0] } } },
                    active: { filters: { enabled: { value: false } } },
                    name: { search: { value: '0', matchType: 'equals' } },
                },
            },
            definition,
        );
        expect(requested.fields.quantity?.filters?.count?.value).toEqual([null, 0]);
        expect(requested.fields.active?.filters?.enabled?.value).toBe(false);
        expect(requested.fields.name?.search?.value).toBe('0');
    });
    it.each([
        { fields: { quantity: { search: { value: '0', matchType: 'equals' } } } },
        { fields: { quantity: { search: { value: 0, matchType: 'contains' } } } },
        { fields: { quantity: { filters: { count: { value: 0 } } } } },
        { fields: { active: { filters: { enabled: { value: 0 } } } } },
        { fields: { name: { search: { value: 'x', matchType: 'equals', extra: true } } } },
        { fields: { missing: { search: { value: 'x', matchType: 'equals' } } } },
        { global: { search: { value: 'x' } } },
        {
            order: {
                kind: 'fields',
                terms: [{ field: 'name', direction: 'asc', sortType: 'numeric' }],
            },
        },
        { paging: { mode: 'offset', current: 1, per_page: 999 } },
    ])('rejects incompatible complete snapshots %#', (change) => {
        expect(() =>
            parseRequestedV2({ ...requestedV2(), ...change }, parseDefinition(definitionV2())),
        ).toThrow();
    });
    it('clears empty search and multiple filters without dropping unrelated criteria', () => {
        const result = parseRequestedV2(
            {
                ...requestedV2(),
                fields: {
                    name: {
                        search: { value: '', matchType: 'contains' },
                        filters: { catalog: { value: 'Notebook' } },
                    },
                    quantity: { filters: { count: { value: [] } } },
                },
            },
            parseDefinition(definitionV2()),
        );
        expect(result.fields).toEqual({ name: { filters: { catalog: { value: 'Notebook' } } } });
    });
    it('rejects expanded decoder definitions and incompatible current selections', () => {
        const original = parseDefinition(definitionV2());
        const expanded = parseDefinition({
            ...definitionV2(),
            fields: {
                ...definitionV2().fields,
                secret: { search: null, order: null, filters: null },
            },
        });
        expect(() => assertNarrowing(original, expanded)).toThrow();
        const narrowed = parseDefinition({ ...definitionV2(), fields: {} });
        expect(() => assertNarrowing(original, narrowed)).not.toThrow();
        expect(() =>
            parseRequestedV2(
                {
                    ...requestedV2(),
                    fields: { name: { search: { value: 'Notebook', matchType: 'equals' } } },
                },
                narrowed,
            ),
        ).toThrow();
    });
    it.each(['', 'bad.name', '1invalid'])(
        'rejects malformed public field identifiers %s',
        (name) => {
            const definition = definitionV2();
            expect(() =>
                parseDefinition({ ...definition, fields: { [name]: definition.fields.name } }),
            ).toThrow();
            expect(() => parseRequestedV2({ ...requestedV2(), fields: { [name]: {} } })).toThrow();
        },
    );
    it('rejects Boolean search metadata while preserving Boolean filters', () => {
        const value = definitionV2();
        expect(() =>
            parseDefinition({
                ...value,
                fields: {
                    ...value.fields,
                    name: {
                        ...value.fields.name,
                        search: { matchTypes: ['equals'], valueType: 'boolean' },
                    },
                },
            }),
        ).toThrow();
        expect(parseDefinition(value).fields.active?.filters?.enabled?.values).toEqual([
            false,
            true,
        ]);
    });
    it('requires an explicit matching failure protocol', () => {
        const failure = {
            version: 2,
            error: { kind: 'server', code: 'invalid_source', message: 'The source is invalid.' },
        };
        expect(parseFailure(failure)).toBeNull();
        expect(parseFailure(failure, 2)).toEqual(failure.error);
    });
});
