import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { AdapterDefinition, RequestedStateV2 } from '@artisan-toolbox/dapter';
import {
    parseDefinition,
    parseRequestedV2,
    parsePageV2,
    assertNarrowing,
} from '../../resources/js/protocol-v2';
import { reduceRequested } from '../../resources/js/requested-state';
import { definition, intent, page, mount, settle } from './phase2-fixtures';

export const composed = {
    ...definition,
    fields: {
        ...definition.fields,
        amount: {
            ...definition.fields.amount,
            order: { directions: ['asc', 'desc'], sortTypes: ['numeric'] },
        },
    },
    global: {
        multi_order: true,
        multi_order_limit: 2,
        searchMatch: { default: 'all', allowed: ['all', 'any'] },
        search: {
            valueType: 'string',
            targets: {
                name: { valueType: 'string', matchType: 'contains' },
                amount: { valueType: 'number', matchType: 'equals' },
            },
        },
        filters: {
            status: { values: [null, false, 0, '0', 'active'], multiple: false },
            tags: { values: ['a', 'b'], multiple: true, empty: 'clear' },
        },
        order: { presets: [{ id: 'cheapest', label: 'Lowest price' }], pagingModes: ['offset'] },
    },
} as const satisfies AdapterDefinition;
const order = (field: string, multiSort = false) =>
    ({
        kind: 'order',
        field,
        value: {
            direction: 'asc',
            sortType: field === 'amount' ? 'numeric' : 'natural',
            multiSort,
        },
    }) as const;
describe('composed protocol and reducer', () => {
    it('accepts expanded capabilities through a strictly legacy-safe envelope', () => {
        const state = intent({
            global: { search: { value: '0' }, filters: { status: { value: false } } },
            searchMatch: 'any',
            order: {
                kind: 'fields',
                terms: [
                    { field: 'name', direction: 'desc', sortType: 'natural' },
                    { field: 'amount', direction: 'asc', sortType: 'numeric' },
                ],
            },
        });
        const response = page(state, composed);
        expect(parsePageV2(response).requested).toEqual(state);
        expect(() =>
            parsePageV2({
                ...response,
                state: {
                    ...response.state,
                    order: {
                        kind: 'terms',
                        terms: [
                            ...(state.order.kind === 'fields' ? state.order.terms : []),
                        ].reverse(),
                    },
                },
            }),
        ).toThrow();
        expect(
            parsePageV2(page(intent({ order: { kind: 'preset', id: 'cheapest' } }), composed)).state
                .order,
        ).toEqual({ kind: 'preset', id: 'cheapest', identity: 'preset:cheapest', opaque: true });
    });
    it('appends, updates in place, clears one term, and rejects limits without eviction', () => {
        const first = reduceRequested(intent(), order('name'), composed, 3);
        const second = reduceRequested(first, order('amount', true), composed, 3);
        expect(second.order).toMatchObject({
            kind: 'fields',
            terms: [{ field: 'name' }, { field: 'amount' }],
        });
        const updated = reduceRequested(
            second,
            {
                kind: 'order',
                field: 'name',
                value: { direction: 'desc', sortType: 'string', multiSort: true },
            },
            composed,
            3,
        );
        expect(updated.order).toMatchObject({
            terms: [{ field: 'name', direction: 'desc' }, { field: 'amount' }],
        });
        expect(
            reduceRequested(
                updated,
                { kind: 'order', field: 'name', value: null, clear: true },
                composed,
                3,
            ).order,
        ).toMatchObject({ terms: [{ field: 'amount' }] });
        expect(() =>
            reduceRequested(
                first,
                order('amount', true),
                { ...composed, global: { ...composed.global, multi_order_limit: 1 } },
                3,
            ),
        ).toThrow();
        const preset = reduceRequested(
            second,
            { kind: 'preset', value: { id: 'cheapest' } },
            composed,
            3,
        );
        expect(() => reduceRequested(preset, order('amount', true), composed, 3)).toThrow();
        expect(reduceRequested(preset, order('amount'), composed, 3).order).toMatchObject({
            kind: 'fields',
            terms: [{ field: 'amount' }],
        });
    });
    it('validates cold URL state and complete definition constraints without losing scalar identity', () => {
        const state = intent({
            searchMatch: 'any',
            global: {
                search: { value: 'Ada' },
                filters: { status: { value: 0 }, tags: { value: ['a', 'a'] } },
            },
            order: { kind: 'preset', id: 'cheapest' },
        });
        expect(parseRequestedV2(state, composed).global.filters?.tags?.value).toEqual(['a']);
        expect(parseRequestedV2(state).order).toEqual({ kind: 'preset', id: 'cheapest' });
        for (const patch of [
            { global: { search: { value: 0 } } },
            { global: { search: { value: 'Ada', targets: ['name'] } } },
            { global: { filters: { status: { value: true } } } },
            { searchMatch: 'none' },
            { order: { kind: 'fields', terms: [] } },
            { order: { kind: 'preset', id: 'missing' } },
            {
                order: {
                    kind: 'fields',
                    terms: [
                        { field: 'name', direction: 'asc', sortType: 'natural' },
                        { field: 'name', direction: 'desc', sortType: 'natural' },
                    ],
                },
            },
        ])
            expect(() => parseRequestedV2({ ...state, ...patch }, composed)).toThrow();
        expect(
            parseRequestedV2(
                { ...state, global: { search: { value: '' }, filters: { tags: { value: [] } } } },
                composed,
            ).global,
        ).toEqual({});
    });
    it('allows only removal and subset narrowing, retaining fixed search semantics', () => {
        const original = parseDefinition(composed);
        const narrowed = parseDefinition({
            ...composed,
            global: {
                ...composed.global,
                multi_order: false,
                multi_order_limit: 1,
                search: null,
                filters: { status: { values: [0], multiple: false } },
                order: null,
                searchMatch: { default: 'all', allowed: ['all'] },
            },
        });
        expect(() => assertNarrowing(original, narrowed)).not.toThrow();
        for (const global of [
            { ...composed.global, multi_order_limit: 3 },
            {
                ...composed.global,
                search: {
                    ...composed.global.search,
                    targets: { name: { valueType: 'string', matchType: 'equals' } },
                },
            },
            { ...composed.global, filters: { status: { values: ['private'], multiple: false } } },
            {
                ...composed.global,
                order: { presets: [{ id: 'private', label: 'Private' }], pagingModes: ['offset'] },
            },
        ])
            expect(() =>
                assertNarrowing(original, parseDefinition({ ...composed, global })),
            ).toThrow();
    });
    it('validates opt-in effective prefix and identity roles without publishing private SQL', () => {
        const capabilities = {
            ...composed,
            global: {
                ...composed.global,
                stability: {
                    prefix: [{ id: 'rank', direction: 'asc', sortType: 'native' }],
                    identity: [{ id: 'key', direction: 'asc', sortType: 'native' }],
                },
            },
        } as const satisfies AdapterDefinition;
        const response = page(intent(), capabilities);
        expect(parsePageV2(response).state.order).toEqual({ kind: 'source' });
        const state = {
            ...response.state,
            order: {
                kind: 'terms',
                terms: [
                    { field: 'rank', direction: 'asc', sortType: 'native', role: 'prefix' },
                    { field: 'key', direction: 'asc', sortType: 'native', role: 'tie' },
                ],
            },
        };
        expect(parsePageV2({ ...response, state }).state.order).toEqual(state.order);
        expect(() => parsePageV2({ ...response, state, capabilities: composed })).toThrow();
        for (const terms of [
            [...state.order.terms].reverse(),
            [...state.order.terms, state.order.terms[0]],
            state.order.terms.slice(1),
        ])
            expect(() =>
                parsePageV2({ ...response, state: { ...state, order: { kind: 'terms', terms } } }),
            ).toThrow();
    });
});
const original = http.getClient(),
    request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>(),
    cleanups: (() => void)[] = [];
beforeEach(() => {
    request.mockReset();
    http.setClient({ request });
    Object.defineProperty(globalThis, 'Ziggy', {
        configurable: true,
        value: {
            url: 'https://example.test',
            port: null,
            defaults: {},
            routes: { 'dapter.data': { uri: 'dapter/data/{source}', methods: ['POST'] } },
        },
    });
});
afterEach(() => {
    cleanups.splice(0).forEach((cleanup) => cleanup());
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
    vi.useRealTimers();
});
const respond = (data: unknown): HttpResponse => ({
    status: 200,
    data: JSON.stringify(data),
    headers: {},
});
describe('public composed operations', () => {
    it('projects globals, debounces search, flushes following criteria, and rechecks saved permissions', async () => {
        request.mockResolvedValueOnce(respond(page(intent(), composed)));
        request.mockImplementation(async (config) =>
            respond(page(JSON.parse(String(config.data)).state as RequestedStateV2, composed)),
        );
        const mounted = mount({ searchDebounce: 100 });
        cleanups.push(mounted.unmount);
        await settle();
        const search = mounted.handle.global?.search,
            filter = mounted.handle.global?.filters?.status;
        expect(search).toBeTruthy();
        expect(filter).toBeTruthy();
        const delayed = search?.call({ value: 'Ada' });
        expect(request).toHaveBeenCalledTimes(1);
        const following = filter?.call({ value: false });
        expect(await delayed).toEqual({ status: 'superseded' });
        await following;
        expect(request).toHaveBeenCalledTimes(2);
        expect(mounted.handle.requested).toMatchObject({
            global: { search: { value: 'Ada' }, filters: { status: { value: false } } },
        });
        await mounted.handle.setSearchMatch?.('any');
        await mounted.handle.fields?.name?.order?.call({ direction: 'asc', sortType: 'natural' });
        await mounted.handle.fields?.amount?.order?.call({
            direction: 'desc',
            sortType: 'numeric',
            multiSort: true,
        });
        expect(mounted.handle.fields?.amount?.order?.current?.priority).toBe(2);
        await mounted.handle.global?.order?.call({ id: 'cheapest' });
        expect(mounted.handle.requested?.order).toEqual({ kind: 'preset', id: 'cheapest' });
        request.mockResolvedValueOnce(respond(page(intent(), definition)));
        await mounted.handle.paging.reload();
        expect(mounted.handle.setSearchMatch).toBeNull();
        expect(await search?.call({ value: 'old' })).toMatchObject({ status: 'failure' });
        expect(request).toHaveBeenCalledTimes(7);
    });
});
