import { expectTypeOf, it } from 'vite-plus/test';
import { useDataAdapter, type AdapterDefinition, type ReadonlyDeep } from '@artisan-toolbox/dapter';
const decodeDefinition = (value: ReadonlyDeep<AdapterDefinition>) =>
    ({
        ...value,
        fields: {
            name: {
                search: null,
                order: { directions: ['asc', 'desc'], sortTypes: ['natural'] },
                filters: null,
            },
        },
        global: {
            multi_order: true,
            multi_order_limit: 2,
            searchMatch: { default: 'all', allowed: ['all', 'any'] },
            search: {
                valueType: 'string',
                targets: { name: { valueType: 'string', matchType: 'contains' } },
            },
            filters: {
                status: { values: [0, false, 'active'], multiple: false },
                tags: { values: ['a', 'b'], multiple: true, empty: 'clear' },
            },
            order: {
                presets: [{ id: 'cheapest', label: 'Lowest price' }],
                pagingModes: ['offset'],
            },
        },
    }) as const satisfies AdapterDefinition;
it('derives globals, finite values, presets, and multi-order permission from definitions', () => {
    const handle = useDataAdapter('Products', { protocol: 2, decodeDefinition });
    void handle.global?.search.call({ value: 'Ada' });
    void handle.global?.filters.status?.call({ value: 0 });
    void handle.global?.filters.tags?.call({ value: ['a', 'b'] });
    void handle.global?.order.call({ id: 'cheapest' });
    void handle.setSearchMatch?.('any');
    void handle.fields?.name?.order.call({
        direction: 'asc',
        sortType: 'natural',
        multiSort: true,
    });
    expectTypeOf(handle.fields?.name?.order.current?.priority).toEqualTypeOf<number | undefined>();
    // @ts-expect-error Global search takes one string.
    void handle.global?.search.call({ value: 0 });
    // @ts-expect-error Fixed targets belong to the server.
    void handle.global?.search.call({ value: 'Ada', targets: ['name'] });
    // @ts-expect-error Fixed match semantics belong to the server.
    void handle.global?.search.call({ value: 'Ada', matchType: 'equals' });
    // @ts-expect-error Scalar domains preserve primitive types.
    void handle.global?.filters.status?.call({ value: '0' });
    // @ts-expect-error Multiple filters require an array.
    void handle.global?.filters.tags?.call({ value: 'a' });
    // @ts-expect-error Preset identifiers are finite.
    void handle.global?.order.call({ id: 'private' });
    // @ts-expect-error Opaque preset order has no direction.
    void handle.global?.order.call({ id: 'cheapest', direction: 'desc' });
    // @ts-expect-error Opaque preset order has no additive intent.
    void handle.global?.order.call({ id: 'cheapest', multiSort: true });
    // @ts-expect-error Grouping modes are finite.
    void handle.setSearchMatch?.('none');
});
