import { expectTypeOf, it } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type DataAdapterHandle,
    type AsyncOutcome,
    type DataAdapterProps,
    type DataAdapterSlot,
} from '@artisan-toolbox/dapter';

type Product = { id: number; details: { name: string }; labels: string[] };
const decodeRow = (value: unknown): Product => {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('id' in value) ||
        typeof value.id !== 'number'
    )
        throw new Error('Invalid product');
    return { id: value.id, details: { name: 'Product' }, labels: ['Available'] };
};
const decodedProps = { source: 'Products', decodeRow } satisfies DataAdapterProps;
it('derives readonly rows from an actual decoder and leaves source-only rows unknown', () => {
    const inspectUnknown = (slot: DataAdapterSlot<{ source: string }>) => {
        if (slot.ready) {
            expectTypeOf(slot.data).toEqualTypeOf<readonly unknown[]>();
            // @ts-expect-error Source-only rows require narrowing or a decoder.
            void slot.data[0]?.id;
        } else {
            expectTypeOf(slot.data).toEqualTypeOf<readonly []>();
        }
    };
    const inspectDecoded = (slot: DataAdapterSlot<typeof decodedProps>) => {
        if (slot.ready) {
            expectTypeOf(slot.data[0]?.details.name).toEqualTypeOf<string | undefined>();
            // @ts-expect-error Accepted rows cannot be edited.
            slot.data[0]!.details.name = 'Changed';
            // @ts-expect-error Nested decoded arrays are readonly.
            slot.data[0]!.labels.push('Changed');
            // @ts-expect-error Accepted arrays cannot be edited.
            slot.data.push({ id: 1, details: { name: 'Changed' }, labels: [] });
        }
        expectTypeOf(slot.paging.goToNext()).toEqualTypeOf<Promise<AsyncOutcome>>();
        // @ts-expect-error Page numbers must be numbers.
        void slot.paging.goToPage('2');
        // @ts-expect-error Page size must be a number.
        void slot.paging.per_page.setValue('2');
        // @ts-expect-error Slot state is readonly.
        slot.status = 'loading';
        // @ts-expect-error URL persistence is not delivered yet.
        void slot.url;
        // @ts-expect-error Operation controls are not delivered yet.
        void slot.fields;
    };
    // @ts-expect-error A free row type cannot create decoded records.
    DataAdapter<Product>({ source: 'Products' });
    // @ts-expect-error A props type promising a decoder requires that decoder.
    DataAdapter<typeof decodedProps>({ source: 'Products' });
    // @ts-expect-error Future URL props are not supported.
    DataAdapter({ source: 'Products', url: true });
    // @ts-expect-error Definition decoders are not supported.
    DataAdapter({ source: 'Products', decodeDefinition: () => ({}) });
    // @ts-expect-error Custom endpoints are not supported.
    const unsupported: DataAdapterProps = { source: 'Products', endpoint: '/private' };
    void inspectUnknown;
    void inspectDecoded;
    void unsupported;
});

it('preserves callable methods on decoded domain values', () => {
    const props = { source: 'Products', decodeRow: () => ({ createdAt: new Date() }) };
    const inspect = (slot: DataAdapterSlot<typeof props>) => {
        if (slot.ready && slot.data[0]) {
            expectTypeOf(slot.data[0].createdAt.getTime()).toEqualTypeOf<number>();
            expectTypeOf(slot.data[0].createdAt.toISOString()).toEqualTypeOf<string>();
            expectTypeOf(slot.data[0].createdAt.setTime(0)).toEqualTypeOf<number>();
            // @ts-expect-error Decoded row properties are readonly.
            slot.data[0].createdAt = new Date();
        }
    };
    void inspect;
});

it('infers shared and bound rows without exposing controller controls', () => {
    const inspect = () => {
        const unknown = useDataAdapter('Products');
        const typed = useDataAdapter('Products', { decodeRow });
        const borrowed = useDataAdapter(typed);
        expectTypeOf(borrowed).toEqualTypeOf<DataAdapterHandle<Product>>();
        expectTypeOf(unknown).toEqualTypeOf<DataAdapterHandle<unknown>>();
        const bound = DataAdapter({ adapter: typed });
        type Slot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
        expectTypeOf<Extract<Slot, { ready: true }>['data'][number]>().toEqualTypeOf<{
            readonly id: number;
            readonly details: { readonly name: string };
            readonly labels: readonly string[];
        }>();
        // @ts-expect-error Source-only acquisition cannot assert arbitrary decoded rows.
        useDataAdapter<Product>('Products');
        // @ts-expect-error Reactive sources require an explicit stable id.
        useDataAdapter(() => 'Products');
        // @ts-expect-error A bound view cannot replace the source.
        DataAdapter({ adapter: typed, source: 'Other' });
        // @ts-expect-error A bound view cannot replace the id.
        DataAdapter({ adapter: typed, id: 'other' });
        // @ts-expect-error A bound view cannot replace the decoder.
        DataAdapter({ adapter: typed, decodeRow });
        // @ts-expect-error Lifecycle controls are not public.
        typed.dispose();
        // @ts-expect-error Lifecycle controls are not public.
        typed.start();
        // @ts-expect-error Transport internals are not public.
        typed.submit();
        // @ts-expect-error Reactive state is readonly.
        typed.ready = true;
    };
    void inspect;
});
