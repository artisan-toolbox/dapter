import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import type { AdapterDefinition } from '@artisan-toolbox/dapter';
import {
    assertNarrowing,
    parseDefinition,
    parseRequestedV2,
} from '../../resources/js/protocol-v2.js';
import { definition, intent, mount, page, settle } from './phase2-fixtures.js';

const advertised = {
    ...definition,
    fields: {
        ...definition.fields,
        embedding: {
            search: {
                valueType: 'vector',
                matchTypes: ['vector'],
                vector: {
                    engine: 'pgsql',
                    metric: 'l2',
                    dimensions: 3,
                    maxDistance: 1,
                    input: 'prepared_vector',
                    precision: 'float32',
                    maxCoordinateMagnitude: 1000000,
                    threshold: 'inclusive',
                    ranking: 'filter_only',
                },
            },
            order: null,
            filters: null,
        },
    },
} as const satisfies AdapterDefinition;
const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
beforeEach(() => {
    request.mockReset();
    http.setClient({ request });
    vi.stubGlobal('Ziggy', {
        url: 'https://example.test',
        port: null,
        defaults: {},
        routes: { 'dapter.data': { uri: 'dapter/data/{source}', methods: ['POST'] } },
    });
});
afterEach(() => {
    for (const cleanup of cleanups.splice(0)) cleanup();
    http.setClient(original);
    vi.unstubAllGlobals();
});
it('sends a prepared vector through a public field handle and clears it explicitly', async () => {
    const initial = intent();
    request.mockResolvedValue({
        status: 200,
        headers: {},
        data: JSON.stringify(page(initial, advertised)),
    });
    const { handle, unmount } = mount({ url: false });
    cleanups.push(unmount);
    await settle();
    const state = intent({
        fields: { embedding: { search: { value: [0, 1, 0], matchType: 'vector' } } },
    });
    request.mockResolvedValueOnce({
        status: 200,
        headers: {},
        data: JSON.stringify(page(state, advertised)),
    });
    expect(
        await handle.fields?.embedding?.search?.call({ value: [0, 1, 0], matchType: 'vector' }),
    ).toEqual({ status: 'success' });
    expect(JSON.parse(String(request.mock.calls.at(-1)?.[0].data))).toEqual({ version: 2, state });
    expect(handle.fields?.embedding?.search?.current).toEqual({
        value: [0, 1, 0],
        matchType: 'vector',
    });
    request.mockResolvedValueOnce({
        status: 200,
        headers: {},
        data: JSON.stringify(page(initial, advertised)),
    });
    expect(await handle.fields?.embedding?.search?.clear()).toEqual({ status: 'success' });
    expect(handle.fields?.embedding?.search?.current).toBeNull();
});
it.each([
    [],
    [1, 2],
    [1, 2, 3, 4],
    [NaN, 0, 0],
    [Infinity, 0, 0],
    [1000001, 0, 0],
    ['1', 0, 0],
    [null, 0, 0],
    Array.from({ length: 3 }),
])('rejects invalid vector input %j against the advertised field', (value) => {
    expect(() =>
        parseRequestedV2(
            { ...intent(), fields: { embedding: { search: { value, matchType: 'vector' } } } },
            advertised,
        ),
    ).toThrow();
});
it('requires vector-only capability metadata and preserves the server distance policy when narrowing', () => {
    const parsed = parseDefinition(advertised);
    expect(parsed.fields.embedding?.search?.vector?.dimensions).toBe(3);
    expect(() =>
        parseDefinition({
            ...advertised,
            fields: {
                embedding: {
                    ...advertised.fields.embedding,
                    search: {
                        ...advertised.fields.embedding.search,
                        matchTypes: ['contains', 'vector'],
                    },
                },
            },
        }),
    ).toThrow();
    const changed = parseDefinition({
        ...advertised,
        fields: {
            embedding: {
                ...advertised.fields.embedding,
                search: {
                    ...advertised.fields.embedding.search,
                    vector: { ...advertised.fields.embedding.search.vector, maxDistance: 2 },
                },
            },
        },
    });
    expect(() => assertNarrowing(parsed, changed)).toThrow();
    expect(() =>
        parseRequestedV2(
            intent({ fields: { name: { search: { value: [0, 0, 0], matchType: 'vector' } } } }),
            advertised,
        ),
    ).toThrow();
});
