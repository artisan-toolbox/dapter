import { http, type HttpRequestConfig, type HttpResponse } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { AdapterDefinition } from '@artisan-toolbox/dapter';
import {
    assertNarrowing,
    parseDefinition,
    parseRequestedV2,
} from '../../resources/js/protocol-v2.js';
import { definition, intent, mount, page, settle } from './phase2-fixtures.js';
import { comparisonDefinition, fullText, locale } from './phase6-fixtures.js';
import { advancedIntent, advancedPage, mountAdvanced } from './phase4-fixtures.js';

const original = http.getClient();
const request = vi.fn<(config: HttpRequestConfig) => Promise<HttpResponse>>();
const cleanups: (() => void)[] = [];
const respond = (data: unknown): HttpResponse => ({
    status: 200,
    data: JSON.stringify(data),
    headers: {},
});
function create(...args: Parameters<typeof mount>) {
    const mounted = mount(...args);
    cleanups.push(mounted.unmount);
    return mounted;
}
function sent() {
    return JSON.parse(String(request.mock.calls.at(-1)?.[0].data));
}
function withSearch(search: unknown) {
    return {
        ...comparisonDefinition,
        fields: {
            ...comparisonDefinition.fields,
            name: { ...comparisonDefinition.fields.name, search },
        },
    };
}
function withOrder(order: unknown) {
    return {
        ...comparisonDefinition,
        fields: {
            ...comparisonDefinition.fields,
            name: { ...comparisonDefinition.fields.name, order },
        },
    };
}
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
    for (const cleanup of cleanups.splice(0)) cleanup();
    http.setClient(original);
    Reflect.deleteProperty(globalThis, 'Ziggy');
});

describe('comparison policy boundaries', () => {
    it('preserves old definitions and deeply freezes exact comparison metadata', () => {
        expect(parseDefinition(definition)).toEqual(definition);
        const parsed = parseDefinition(comparisonDefinition);
        expect(parsed).toEqual(comparisonDefinition);
        expect(Object.isFrozen(parsed.fields.name?.search?.fullText)).toBe(true);
        expect(Object.isFrozen(parsed.fields.name?.order?.locale)).toBe(true);
        expect(Object.isFrozen(parsed.global.search?.targets.description?.fullText)).toBe(true);
    });
    it.each([
        { ...fullText, engine: 'mysql' },
        { ...fullText, configuration: 'english' },
        { ...fullText, configuration: 'pg_catalog.english; SELECT 1' },
        { ...fullText, input: 'query' },
        { ...fullText, maxBytes: 513 },
        { ...fullText, ranking: 'rank' },
        { ...fullText, extra: true },
        null,
        undefined,
    ])('rejects malformed field and fixed global full-text policy %j', (policy) => {
        expect(() =>
            parseDefinition(
                withSearch({ ...comparisonDefinition.fields.name.search, fullText: policy }),
            ),
        ).toThrow();
        expect(() =>
            parseDefinition({
                ...comparisonDefinition,
                global: {
                    ...comparisonDefinition.global,
                    search: {
                        valueType: 'string',
                        targets: {
                            name: { valueType: 'string', matchType: 'full_text', fullText: policy },
                        },
                    },
                },
            }),
        ).toThrow();
    });
    it('rejects missing, misplaced, numeric and arbitrary regex comparisons', () => {
        expect(() =>
            parseDefinition(withSearch({ valueType: 'string', matchTypes: ['full_text'] })),
        ).toThrow();
        expect(() =>
            parseDefinition(
                withSearch({ valueType: 'string', matchTypes: ['contains'], fullText }),
            ),
        ).toThrow();
        expect(() =>
            parseDefinition(
                withSearch({ valueType: 'integer', matchTypes: ['full_text'], fullText }),
            ),
        ).toThrow();
        expect(() =>
            parseDefinition(withSearch({ valueType: 'string', matchTypes: ['regex'] })),
        ).toThrow();
        expect(() =>
            parseDefinition(
                withOrder({ ...comparisonDefinition.fields.name.order, sortTypes: ['string'] }),
            ),
        ).toThrow();
    });
    it.each([
        { ...locale, strength: 'primary' },
        { ...locale, normalization: false },
        { ...locale, numeric: true },
        { ...locale, locale: '' },
        { ...locale, validLocale: null },
        { ...locale, actualLocale: '' },
        { ...locale, icuVersion: 78 },
        { ...locale, extra: true },
        null,
    ])('rejects malformed locale metadata %j', (policy) => {
        expect(() =>
            parseDefinition(
                withOrder({ ...comparisonDefinition.fields.name.order, locale: policy }),
            ),
        ).toThrow();
    });
    it('rejects locale cursor capabilities and preserves legacy locale metadata omission', () => {
        const { locale: _policy, ...legacyOrder } = comparisonDefinition.fields.name.order;
        expect(parseDefinition(withOrder(legacyOrder)).fields.name?.order).toEqual(legacyOrder);
        expect(() =>
            parseDefinition({
                ...comparisonDefinition,
                paging: {
                    mode: 'cursor',
                    per_page: 2,
                    per_page_options: [2],
                    counts: 'unknown',
                    expires_after: 900,
                },
            }),
        ).toThrow('Unsupported cursor locale');
    });
    it('rejects locale cursor stability prefixes even when field controls are hidden', () => {
        expect(() =>
            parseDefinition({
                ...definition,
                global: {
                    ...definition.global,
                    stability: {
                        prefix: [{ id: 'fixed_label', direction: 'asc', sortType: 'locale' }],
                        identity: [],
                    },
                },
                paging: {
                    mode: 'cursor',
                    per_page: 2,
                    per_page_options: [2],
                    counts: 'unknown',
                    expires_after: 900,
                },
            }),
        ).toThrow('Unsupported cursor locale');
    });
    it('requires identical retained policies while permitting removal of their modes', () => {
        const source = parseDefinition(comparisonDefinition);
        const changedSearch = parseDefinition(
            withSearch({
                ...comparisonDefinition.fields.name.search,
                fullText: { ...fullText, configuration: 'pg_catalog.portuguese' },
            }),
        );
        const changedLocale = parseDefinition(
            withOrder({
                ...comparisonDefinition.fields.name.order,
                locale: { ...locale, actualLocale: 'pt' },
            }),
        );
        expect(() => assertNarrowing(source, changedSearch)).toThrow('Expanded search');
        expect(() => assertNarrowing(source, changedLocale)).toThrow('Expanded order');
        const { locale: _policy, ...withoutLocale } = comparisonDefinition.fields.name.order;
        expect(() => assertNarrowing(source, parseDefinition(withOrder(withoutLocale)))).toThrow(
            'Expanded order',
        );
        const removed = parseDefinition({
            ...comparisonDefinition,
            fields: {
                ...comparisonDefinition.fields,
                name: {
                    search: { valueType: 'string', matchTypes: ['contains'] },
                    order: { directions: ['asc'], sortTypes: ['string'] },
                    filters: null,
                },
            },
        });
        expect(() => assertNarrowing(source, removed)).not.toThrow();
        expect(() => assertNarrowing(removed, source)).toThrow();
    });
    it('keeps fixed global targets and their policies exactly equal', () => {
        const source = parseDefinition(comparisonDefinition);
        for (const targets of [
            {
                ...comparisonDefinition.global.search.targets,
                description: {
                    valueType: 'string',
                    matchType: 'full_text',
                    fullText: { ...fullText, configuration: 'pg_catalog.portuguese' },
                },
            },
            { name: comparisonDefinition.global.search.targets.name },
            {
                ...comparisonDefinition.global.search.targets,
                extra: comparisonDefinition.global.search.targets.name,
            },
            {
                ...comparisonDefinition.global.search.targets,
                description: { valueType: 'string', matchType: 'contains' },
            },
        ]) {
            const changed = parseDefinition({
                ...comparisonDefinition,
                global: {
                    ...comparisonDefinition.global,
                    search: { valueType: 'string', targets },
                },
            });
            expect(() => assertNarrowing(source, changed)).toThrow('Expanded definition');
        }
        expect(() =>
            assertNarrowing(
                source,
                parseDefinition({
                    ...comparisonDefinition,
                    global: { ...comparisonDefinition.global, search: null },
                }),
            ),
        ).not.toThrow();
    });
    it.each(['a'.repeat(513), 'é'.repeat(257), 'a\0b', '\ud800', '\udfff'])(
        'rejects invalid full-text input without changing clear semantics',
        (value) => {
            const state = intent({
                fields: { name: { search: { value, matchType: 'full_text' } } },
            });
            expect(() => parseRequestedV2(state, comparisonDefinition)).toThrow(
                'Invalid full-text input',
            );
            expect(() =>
                parseRequestedV2(intent({ global: { search: { value } } }), comparisonDefinition),
            ).toThrow('Invalid full-text input');
            expect(
                parseRequestedV2(
                    intent({ fields: { name: { search: { value: '', matchType: 'full_text' } } } }),
                    comparisonDefinition,
                ).fields,
            ).toEqual({});
        },
    );
    it('accepts literal Unicode plain text at the exact UTF-8 byte boundary', () => {
        for (const value of ['é'.repeat(256), '😀'.repeat(128), 'running & cats | dogs !not:*']) {
            const state = intent({
                fields: { name: { search: { value, matchType: 'full_text' } } },
            });
            expect(parseRequestedV2(state, comparisonDefinition)).toEqual(state);
        }
        const longLiteral = intent({
            fields: { name: { search: { value: 'a'.repeat(513), matchType: 'contains' } } },
        });
        expect(parseRequestedV2(longLiteral, comparisonDefinition)).toEqual(longLiteral);
    });
});

describe('comparison controls through the public controller', () => {
    it('restores exact requested comparison state and keeps projected rows independent', async () => {
        const restored = intent({
            fields: {
                name: { search: { value: 'running cats', matchType: 'full_text' } },
                status: { filters: { selected: { value: null } } },
            },
            global: { search: { value: 'cats' } },
            order: {
                kind: 'fields',
                terms: [{ field: 'name', direction: 'asc', sortType: 'locale' }],
            },
        });
        request.mockResolvedValueOnce(respond(page(restored, comparisonDefinition)));
        const { handle } = create({
            decodeRow() {
                return { record: 'resource-label', status: null };
            },
        });
        await settle();
        expect(handle.ready).toBe(true);
        expect(handle.requested).toEqual(restored);
        expect(handle.data[0]).toEqual({ record: 'resource-label', status: null });
        expect(handle.fields?.record).toBeUndefined();
        expect(handle.fields?.name?.search?.available.fullText).toEqual(fullText);
        expect(handle.fields?.name?.order?.available.locale).toEqual(locale);
        expect(handle.fields?.status?.filters?.selected?.current).toEqual({ value: null });
        expect(handle.global?.search?.available.targets.description?.fullText).toEqual(fullText);
        const changed = intent({
            ...restored,
            fields: {
                ...restored.fields,
                name: { search: { value: 'cat', matchType: 'full_text' } },
            },
        });
        request.mockResolvedValueOnce(respond(page(changed, comparisonDefinition)));
        expect(
            await handle.fields?.name?.search?.call({ value: 'cat', matchType: 'full_text' }),
        ).toEqual({ status: 'success' });
        expect(sent()).toEqual({ version: 2, state: changed });
        expect(JSON.stringify(sent())).not.toContain('configuration');
        expect(JSON.stringify(sent())).not.toContain('validLocale');
    });
    it('rejects unsupported controls and forged replacements before a request or mutation', async () => {
        request.mockResolvedValueOnce(respond(page(intent(), comparisonDefinition)));
        const { handle } = create();
        await settle();
        const before = handle.requested;
        for (const value of ['x'.repeat(513), 'x\0y']) {
            expect(
                await handle.fields?.name?.search?.call({ value, matchType: 'full_text' }),
            ).toMatchObject({ status: 'failure', error: { kind: 'validation' } });
            expect(await handle.global?.search?.call({ value })).toMatchObject({
                status: 'failure',
            });
        }
        for (const state of [
            intent({ fields: { amount: { search: { value: 1, matchType: 'full_text' } } } }),
            { ...intent(), fields: { name: { search: { value: 'x', matchType: 'regex' } } } },
            { ...intent(), global: { search: { value: 'x', configuration: 'pg_catalog.simple' } } },
            {
                ...intent(),
                fields: { name: { search: { value: 'x', matchType: 'full_text', fullText } } },
            },
        ]) {
            expect(await handle.replaceState(state)).toMatchObject({
                status: 'failure',
                error: { kind: 'validation' },
            });
        }
        expect(handle.requested).toBe(before);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('revalidates same-context stale controls against the latest narrowed definition', async () => {
        request.mockResolvedValueOnce(respond(page(intent(), comparisonDefinition)));
        const { handle } = create();
        await settle();
        const oldSearch = handle.fields?.name?.search;
        const oldOrder = handle.fields?.name?.order;
        const narrowed = {
            ...comparisonDefinition,
            fields: {
                ...comparisonDefinition.fields,
                name: {
                    search: { valueType: 'string', matchTypes: ['contains'] },
                    order: { directions: ['asc'], sortTypes: ['string'] },
                    filters: null,
                },
            },
        } satisfies AdapterDefinition;
        request.mockResolvedValueOnce(respond(page(intent(), narrowed)));
        await handle.paging.reload();
        const current = handle.requested;
        expect(await oldSearch?.call({ value: 'cat', matchType: 'full_text' })).toMatchObject({
            status: 'failure',
            error: { kind: 'validation' },
        });
        expect(await oldOrder?.call({ direction: 'asc', sortType: 'locale' })).toMatchObject({
            status: 'failure',
            error: { kind: 'validation' },
        });
        expect(handle.requested).toBe(current);
        expect(request).toHaveBeenCalledTimes(2);
        const expected = intent({
            fields: { name: { search: { value: 'cat', matchType: 'contains' } } },
        });
        request.mockResolvedValueOnce(respond(page(expected, narrowed)));
        expect(await oldSearch?.call({ value: 'cat', matchType: 'contains' })).toEqual({
            status: 'success',
        });
        expect(sent()).toEqual({ version: 2, state: expected });
    });
    it('invalidates old-context comparison controls even when a new context supports the same modes', async () => {
        request.mockResolvedValueOnce(respond(page(intent(), comparisonDefinition)));
        const { handle } = create();
        await settle();
        const old = handle.fields?.name?.search;
        request.mockResolvedValueOnce(respond(page(intent(), comparisonDefinition)));
        await handle.resetContext();
        await settle();
        const before = request.mock.calls.length;
        expect(await old?.call({ value: 'cat', matchType: 'full_text' })).toEqual({
            status: 'superseded',
        });
        expect(request).toHaveBeenCalledTimes(before);
    });
    it('rejects decoder policy changes and accepted-state-incompatible removals', async () => {
        for (const alter of ['policy', 'mode'] as const) {
            const selected = intent({
                fields: { name: { search: { value: 'cat', matchType: 'full_text' } } },
            });
            request.mockResolvedValueOnce(respond(page(selected, comparisonDefinition)));
            const { handle } = create({
                id: `comparison-${alter}`,
                decodeDefinition(value) {
                    return {
                        ...value,
                        fields: {
                            ...value.fields,
                            name: {
                                ...comparisonDefinition.fields.name,
                                search:
                                    alter === 'policy'
                                        ? {
                                              ...comparisonDefinition.fields.name.search,
                                              fullText: {
                                                  ...fullText,
                                                  configuration: 'pg_catalog.simple',
                                              },
                                          }
                                        : { valueType: 'string', matchTypes: ['contains'] },
                            },
                        },
                    };
                },
            });
            await settle();
            expect(handle.ready).toBe(false);
            expect(handle.error?.kind).toBe('protocol');
        }
    });
    it('allows full-text filtering with native cursor order and rejects locale selection', async () => {
        const cursorDefinition = {
            ...comparisonDefinition,
            fields: {
                ...comparisonDefinition.fields,
                name: {
                    ...comparisonDefinition.fields.name,
                    order: { directions: ['asc'], sortTypes: ['native'] },
                },
            },
            paging: {
                mode: 'cursor',
                counts: 'unknown',
                expires_after: 900,
                per_page: 2,
                per_page_options: [2],
            },
        } as const satisfies AdapterDefinition;
        const state = {
            ...intent(),
            fields: { name: { search: { value: 'running', matchType: 'full_text' } } },
            order: {
                kind: 'fields',
                terms: [{ field: 'name', direction: 'asc', sortType: 'native' }],
            },
            paging: { mode: 'cursor', cursor: 'opaque-token', per_page: 2 },
        };
        expect(parseRequestedV2(state, cursorDefinition)).toEqual(state);
        expect(() =>
            parseRequestedV2(
                {
                    ...state,
                    order: {
                        kind: 'fields',
                        terms: [{ field: 'name', direction: 'asc', sortType: 'locale' }],
                    },
                },
                cursorDefinition,
            ),
        ).toThrow();
    });
});

it.each(['simple', 'cursor'] as const)(
    'resets an accumulated %s window when comparison criteria change',
    async (mode) => {
        const capabilities = {
            ...comparisonDefinition,
            fields: {
                ...comparisonDefinition.fields,
                name: {
                    ...comparisonDefinition.fields.name,
                    order:
                        mode === 'cursor'
                            ? { directions: ['asc'], sortTypes: ['native'] }
                            : comparisonDefinition.fields.name.order,
                },
            },
            paging:
                mode === 'cursor'
                    ? {
                          mode,
                          per_page: 2,
                          per_page_options: [2],
                          counts: 'unknown',
                          expires_after: 900,
                      }
                    : { mode, per_page: 2, per_page_options: [2], counts: 'unknown' },
        } as const satisfies AdapterDefinition;
        const first = advancedIntent(
            mode === 'cursor'
                ? { mode, cursor: null, per_page: 2 }
                : { mode, current: 1, per_page: 2 },
        );
        const second = advancedIntent(
            mode === 'cursor'
                ? { mode, cursor: 'next-token', per_page: 2 }
                : { mode, current: 2, per_page: 2 },
        );
        request.mockResolvedValueOnce(
            respond(advancedPage(first, { capabilities, data: [{ label: 'first' }] })),
        );
        const { handle, unmount } = mountAdvanced({ protocol: 2, infinite: true });
        cleanups.push(unmount);
        await settle();
        request.mockResolvedValueOnce(
            respond(advancedPage(second, { capabilities, data: [{ label: 'second' }] })),
        );
        expect(await handle.infinite?.append()).toEqual({ status: 'success' });
        expect(handle.data).toEqual([{ label: 'first' }, { label: 'second' }]);
        const searched = {
            ...first,
            fields: { name: { search: { value: 'running', matchType: 'full_text' } } },
        } as const;
        request.mockResolvedValueOnce(
            respond(advancedPage(searched, { capabilities, data: [{ label: 'searched' }] })),
        );
        expect(
            await handle.fields?.name?.search?.call({ value: 'running', matchType: 'full_text' }),
        ).toEqual({ status: 'success' });
        expect(handle.data).toEqual([{ label: 'searched' }]);
        expect(sent()).toEqual({ version: 2, state: searched });
        const current = handle.requested;
        const before = request.mock.calls.length;
        expect(
            await handle.fields?.name?.search?.call(
                { value: 'cat', matchType: 'full_text' },
                { page: 'preserve' },
            ),
        ).toMatchObject({ status: 'failure' });
        expect(handle.requested).toBe(current);
        if (mode === 'cursor') {
            expect(
                await handle.fields?.name?.order?.call({ direction: 'asc', sortType: 'locale' }),
            ).toMatchObject({ status: 'failure' });
            expect(request).toHaveBeenCalledTimes(before);
        } else {
            const ordered = {
                ...searched,
                order: {
                    kind: 'fields',
                    terms: [{ field: 'name', direction: 'asc', sortType: 'locale' }],
                },
            } as const;
            request.mockResolvedValueOnce(
                respond(advancedPage(ordered, { capabilities, data: [{ label: 'ordered' }] })),
            );
            expect(
                await handle.fields?.name?.order?.call({ direction: 'asc', sortType: 'locale' }),
            ).toEqual({ status: 'success' });
            expect(handle.data).toEqual([{ label: 'ordered' }]);
            expect(sent()).toEqual({ version: 2, state: ordered });
        }
    },
);
