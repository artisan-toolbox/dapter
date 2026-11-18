import { ref } from 'vue';
import { expectTypeOf, it } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type AdapterDefinition,
    type DataAdapterHandle,
    type DataAdapterSlot,
    type ReadonlyDeep,
} from '@artisan-toolbox/dapter';

const decodeDefinition = (value: ReadonlyDeep<AdapterDefinition>) =>
    ({
        ...value,
        global: {
            multi_order: false,
            multi_order_limit: 1,
            searchMatch: { default: 'all', allowed: ['all'] },
            search: null,
            filters: null,
            order: null,
        },
        fields: {
            title: {
                search: { matchTypes: ['contains'], valueType: 'string' },
                order: { directions: ['asc'], sortTypes: ['natural'] },
                filters: null,
            },
            score: {
                search: { matchTypes: ['equals'], valueType: 'number' },
                order: null,
                filters: null,
            },
            status: {
                search: null,
                order: null,
                filters: {
                    selected: { values: ['active', 'archived', null], multiple: false },
                    labels: { values: ['red', 'blue'], multiple: true, empty: 'clear' },
                },
            },
        },
    }) as const satisfies AdapterDefinition;
const decodeRow = (_value: unknown) => ({ id: 1, title: 'Example', nested: { tags: ['one'] } });
const props = { source: 'Products', protocol: 2, decodeDefinition, decodeRow } as const;

it('infers operation names and operands independently of decoded row keys', () => {
    const inspect = (slot: DataAdapterSlot<typeof props>) => {
        expectTypeOf(slot.fields?.title?.search?.available.matchTypes).toEqualTypeOf<
            readonly ['contains'] | undefined
        >();
        expectTypeOf(slot.fields?.score?.order).toEqualTypeOf<null | undefined>();
        expectTypeOf(slot.fields?.status?.search).toEqualTypeOf<null | undefined>();
        void slot.fields?.title?.search.call({ value: 'Ada', matchType: 'contains' });
        void slot.fields?.score?.search.call({ value: 1.5, matchType: 'equals' });
        void slot.fields?.status?.filters.selected?.call({ value: null });
        void slot.fields?.status?.filters.labels?.call({ value: ['red', 'blue'] });
        void slot.fields?.title?.order.call(
            { direction: 'asc', sortType: 'natural', multiSort: false },
            { page: 'preserve' },
        );
        // @ts-expect-error Null is not an operation input; clearing has its own method.
        void slot.fields?.title?.search.call(null);
        // @ts-expect-error Missing inputs are not valid filter selections.
        void slot.fields?.status?.filters.selected?.call(undefined);
        // @ts-expect-error Record keys do not imply declared operations.
        void slot.fields?.id;
        // @ts-expect-error Permission-narrowed fields may be absent.
        void slot.fields?.title.search;
        // @ts-expect-error Match operands are narrowed by the definition decoder.
        void slot.fields?.title?.search.call({ value: 'Ada', matchType: 'equals' });
        // @ts-expect-error Numeric search cannot accept text.
        void slot.fields?.score?.search.call({ value: '1.5', matchType: 'equals' });
        // @ts-expect-error Text search cannot accept numeric operands.
        void slot.fields?.title?.search.call({ value: 1, matchType: 'contains' });
        // @ts-expect-error Named filter values are finite.
        void slot.fields?.status?.filters.selected?.call({ value: 'private' });
        // @ts-expect-error Single filters reject arrays.
        void slot.fields?.status?.filters.selected?.call({ value: ['active'] });
        // @ts-expect-error Multiple filters require arrays.
        void slot.fields?.status?.filters.labels?.call({ value: 'red' });
        // @ts-expect-error Multiple filter entries are finite.
        void slot.fields?.status?.filters.labels?.call({ value: ['green'] });
        // @ts-expect-error Permission-narrowed named filters may be absent.
        void slot.fields?.status?.filters.selected.call({ value: 'active' });
        // @ts-expect-error Ordering directions are narrowed.
        void slot.fields?.title?.order.call({ direction: 'desc', sortType: 'natural' });
        // @ts-expect-error Ordering modes are narrowed.
        void slot.fields?.title?.order.call({ direction: 'asc', sortType: 'numeric' });
        void slot.fields?.title?.order.call({
            direction: 'asc',
            sortType: 'natural',
            // @ts-expect-error Multi-order is unavailable.
            multiSort: true,
        });
        // @ts-expect-error Global search remains unavailable.
        void slot.global?.search.call({ value: 'Ada' });
        // @ts-expect-error Available capabilities remain deeply readonly.
        slot.fields?.title?.search.available.matchTypes.push('contains');
        if (slot.ready) {
            expectTypeOf(slot.data[0]?.nested.tags).toEqualTypeOf<readonly string[] | undefined>();
            // @ts-expect-error Decoded nested records remain readonly.
            slot.data[0]!.nested.tags.push('two');
        }
    };
    void inspect;
});

it('preserves both decoder inferences through shared handles and bound slots', () => {
    const inspect = () => {
        // @ts-expect-error A free definition generic cannot invent validated operations.
        DataAdapter<{ source: string }, unknown, ReturnType<typeof decodeDefinition>>({
            source: 'Products',
        });
        const handle = useDataAdapter('Products', { protocol: 2, decodeRow, decodeDefinition });
        const borrowed = useDataAdapter(handle);
        expectTypeOf(borrowed).toEqualTypeOf<
            DataAdapterHandle<ReturnType<typeof decodeRow>, ReturnType<typeof decodeDefinition>>
        >();
        const bound = DataAdapter({ adapter: handle });
        type Slot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
        expectTypeOf<Slot['fields']>().toEqualTypeOf<DataAdapterSlot<typeof props>['fields']>();
        const definitionOnly = useDataAdapter('Products', { protocol: 2, decodeDefinition });
        if (definitionOnly.ready) {
            expectTypeOf(definitionOnly.data).toEqualTypeOf<readonly unknown[]>();
            // @ts-expect-error Definition inference cannot invent decoded rows.
            void definitionOnly.data[0]?.id;
        }
        const rowOnly = useDataAdapter('Products', { protocol: 2, decodeRow });
        void rowOnly.fields?.arbitrary?.search?.call({ value: 'Ada', matchType: 'contains' });
        // @ts-expect-error A bound view cannot replace its protocol.
        DataAdapter({ adapter: handle, protocol: 2 });
        // @ts-expect-error A bound view cannot replace its definition decoder.
        DataAdapter({ adapter: handle, decodeDefinition });
        // @ts-expect-error A bound view cannot replace its debounce.
        DataAdapter({ adapter: handle, searchDebounce: 100 });
        // @ts-expect-error A bound view cannot replace its URL policy.
        DataAdapter({ adapter: handle, url: false });
        // @ts-expect-error A bound view cannot replace its scroll target.
        DataAdapter({ adapter: handle, scrollContainer: null });
        const legacy: DataAdapterHandle<ReturnType<typeof decodeRow>> = rowOnly;
        void legacy;
    };
    void inspect;
});

it('preserves legacy explicit row generics with required decoders and reactive source ids', () => {
    const inspect = () => {
        type Product = ReturnType<typeof decodeRow>;
        const source = ref('Products');
        const staticHandle = useDataAdapter<Product>('Products', { decodeRow });
        const refHandle = useDataAdapter<Product>(source, { id: 'legacy-ref', decodeRow });
        const getterHandle = useDataAdapter<Product>(() => source.value, {
            id: 'legacy-getter',
            decodeRow,
        });
        expectTypeOf(staticHandle).toEqualTypeOf<DataAdapterHandle<Product>>();
        expectTypeOf(refHandle).toEqualTypeOf<DataAdapterHandle<Product>>();
        expectTypeOf(getterHandle).toEqualTypeOf<DataAdapterHandle<Product>>();
        // @ts-expect-error An explicit row generic still requires a real decoder.
        useDataAdapter<Product>('Products');
        // @ts-expect-error A reactive source still requires its stable explicit id.
        useDataAdapter<Product>(source, { decodeRow });
        // @ts-expect-error The decoder must return the promised row contract.
        useDataAdapter<Product>('Products', { decodeRow: () => ({ id: 'invalid' }) });
    };
    void inspect;
});
