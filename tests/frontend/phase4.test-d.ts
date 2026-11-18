import { expectTypeOf, it } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type AsyncOutcome,
    type CursorPaging,
    type DataAdapterHandle,
    type DataAdapterProps,
    type DataAdapterOptions,
    type SimplePaging,
    type SourcePaging,
    type UnreadyPaging,
} from '@artisan-toolbox/dapter';

it('retains offset defaults and narrows source paging in creators, borrowers and bound slots', () => {
    const inspect = () => {
        const legacy = useDataAdapter('Products');
        const offset = useDataAdapter('Products', { protocol: 2 });
        expectTypeOf(legacy.paging.goToPage(2)).toEqualTypeOf<Promise<AsyncOutcome>>();
        expectTypeOf(offset.paging.goToLast()).toEqualTypeOf<Promise<AsyncOutcome>>();
        const owner = useDataAdapter('Feed', { protocol: 2, paging: 'source' });
        const borrower = useDataAdapter(owner);
        expectTypeOf(borrower).toEqualTypeOf<typeof owner>();
        expectTypeOf(owner.paging).toEqualTypeOf<SourcePaging>();
        const component = DataAdapter({ adapter: borrower });
        type Slot = Parameters<NonNullable<typeof component.__ctx>['slots']['default']>[0];
        expectTypeOf(owner.share()).toEqualTypeOf<string | null>();
        expectTypeOf<Slot['share']>().toEqualTypeOf<() => string | null>();
        type Exposed = Parameters<NonNullable<typeof component.__ctx>['expose']>[0];
        expectTypeOf<Exposed['share']>().toEqualTypeOf<() => string | null>();
        const sourceComponent = DataAdapter({ source: 'Feed', protocol: 2, paging: 'source' });
        type SourceSlot = Parameters<
            NonNullable<typeof sourceComponent.__ctx>['slots']['default']
        >[0];
        expectTypeOf<SourceSlot['share']>().toEqualTypeOf<() => string | null>();
        expectTypeOf<Slot['paging']>().toEqualTypeOf<SourcePaging>();
        // @ts-expect-error Advanced source handles cannot be erased into offset handles.
        const erased: DataAdapterHandle = borrower;
        // @ts-expect-error Props preserve the required protocol opt-in.
        const invalidProps: DataAdapterProps = { source: 'Feed', paging: 'source' };
        // @ts-expect-error Options preserve the required protocol opt-in.
        const invalidOptions: DataAdapterOptions = { paging: 'source' };
        void invalidProps;
        void invalidOptions;
        // @ts-expect-error Source paging requires protocol 2.
        useDataAdapter('Feed', { paging: 'source' });
        // @ts-expect-error Protocol 1 cannot request source paging.
        useDataAdapter('Feed', { protocol: 1, paging: 'source' });
        // @ts-expect-error Components require the same explicit protocol opt-in.
        DataAdapter({ source: 'Feed', paging: 'source' });
        // @ts-expect-error Bound components cannot replace their creator's paging mode.
        DataAdapter({ adapter: owner, paging: 'offset' });
        // @ts-expect-error Numbered actions require mode narrowing.
        void borrower.paging.goToPage(2);
        // @ts-expect-error Last-page actions require mode narrowing.
        void borrower.paging.goToLast();
        switch (borrower.paging.mode) {
            case 'cursor':
                expectTypeOf(borrower.paging).toEqualTypeOf<CursorPaging>();
                expectTypeOf(borrower.paging.current).toEqualTypeOf<null>();
                expectTypeOf(borrower.paging.pages).toEqualTypeOf<null>();
                expectTypeOf(borrower.paging.goToPage).toEqualTypeOf<null>();
                if (borrower.paging.counts === 'unknown') {
                    expectTypeOf(borrower.paging.total).toEqualTypeOf<null>();
                    expectTypeOf(borrower.paging.filtered).toEqualTypeOf<null>();
                } else {
                    expectTypeOf(borrower.paging.total).toEqualTypeOf<number>();
                    expectTypeOf(borrower.paging.filtered).toEqualTypeOf<number>();
                }
                break;
            case 'simple':
                expectTypeOf(borrower.paging).toEqualTypeOf<SimplePaging>();
                expectTypeOf(borrower.paging.current).toEqualTypeOf<number>();
                expectTypeOf(borrower.paging.per_page.value).toEqualTypeOf<number>();
                expectTypeOf(borrower.paging.goToLast).toEqualTypeOf<null>();
                break;
            case null:
                expectTypeOf(borrower.paging).toEqualTypeOf<UnreadyPaging>();
                expectTypeOf(borrower.paging.counts).toEqualTypeOf<null>();
                expectTypeOf(borrower.paging.has_next).toEqualTypeOf<false>();
                expectTypeOf(borrower.paging.per_page.value).toEqualTypeOf<null>();
                expectTypeOf(borrower.paging.per_page.options).toEqualTypeOf<readonly []>();
                break;
            case 'offset':
                void borrower.paging.goToPage(2);
                void borrower.paging.goToLast();
                break;
        }
        void erased;
    };
    void inspect;
});

it('preserves source paging when creator options are a runtime union', () => {
    const inspect = (options: DataAdapterOptions) => {
        const handle = useDataAdapter('Feed', options);
        expectTypeOf(handle.paging).toEqualTypeOf<SourcePaging>();
        // @ts-expect-error A union of creator options can negotiate cursor or simple paging.
        void handle.paging.goToLast();
        // @ts-expect-error A union of creator options can negotiate cursor or simple paging.
        void handle.paging.goToPage(2);
    };
    void inspect;
});
