import { expectTypeOf, it } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type DataAdapterProps,
    type DataAdapterOptions,
    type DataAdapterSlot,
} from '@artisan-toolbox/dapter';
import { decodeLiteralDefinition, decodeResourceRow } from './consumer/comparison-types.js';

const props = {
    source: 'People',
    protocol: 2,
    decodeDefinition: decodeLiteralDefinition,
    decodeRow: decodeResourceRow,
} as const;

it('keeps literal operations finite, nullable and separate from Resource rows and source policy', () => {
    const inspect = (slot: DataAdapterSlot<typeof props>) => {
        expectTypeOf(slot.fields?.name?.search.current).toEqualTypeOf<
            | {
                  readonly value: string;
                  readonly matchType: 'contains' | 'equals' | 'starts_with' | 'ends_with';
              }
            | null
            | undefined
        >();
        expectTypeOf(slot.global?.search.current).toEqualTypeOf<
            { readonly value: string } | null | undefined
        >();
        for (const matchType of ['contains', 'equals', 'starts_with', 'ends_with'] as const)
            void slot.fields?.name?.search.call({ value: 'Joa\u0303o%_!\\', matchType });
        void slot.fields?.name?.search.clear();
        void slot.global?.search.call({ value: 'JoÃo' });
        void slot.global?.search.clear();
        // @ts-expect-error A literal declaration does not grant full-text operations.
        void slot.fields?.name?.search.call({ value: 'Joao', matchType: 'full_text' });
        // @ts-expect-error A String field does not accept numeric equality.
        void slot.fields?.name?.search.call({ value: 1, matchType: 'equals' });
        // @ts-expect-error Clearing is explicit and does not accept a null operation.
        void slot.fields?.name?.search.call(null);
        // @ts-expect-error Permission narrowing can remove the field.
        void slot.fields?.name.search;
        // @ts-expect-error Resource projection does not grant field operations.
        void slot.fields?.record;
        // @ts-expect-error Literal strictness belongs to the source declaration.
        void slot.fields?.name?.search.call({ value: 'Joao', matchType: 'equals', strict: true });
        void slot.fields?.name?.search.call({
            value: 'Joao',
            matchType: 'contains',
            // @ts-expect-error Source configuration is not an operation property.
            strictLiteralSearch: true,
        });
        // @ts-expect-error Global requests cannot choose literal policy.
        void slot.global?.search.call({ value: 'Joao', literalSearchPolicy: false });
        // @ts-expect-error Server configuration is not a paging option.
        void slot.global?.search.call({ value: 'Joao' }, { strict_literal_search: true });
        // @ts-expect-error A server flag is not public search metadata.
        void slot.fields?.name?.search.available.strictLiteralSearch;
        // @ts-expect-error Policy does not widen finite nullable filter selection.
        void slot.fields?.status?.filters.selected?.call({ value: 'private' });
        void slot.fields?.status?.filters.selected?.call({ value: null });
        if (slot.ready) {
            expectTypeOf(slot.data[0]?.record.name).toEqualTypeOf<string | undefined>();
            expectTypeOf(slot.data[0]?.nullable).toEqualTypeOf<null | undefined>();
            // @ts-expect-error Definition keys do not determine Resource row shape.
            void slot.data[0]?.name;
        }
    };
    void inspect;
});

it('preserves literal controls on borrowed handles and rejects policy options and props', () => {
    const inspect = () => {
        const owner = useDataAdapter('People', {
            protocol: 2,
            decodeDefinition: decodeLiteralDefinition,
            decodeRow: decodeResourceRow,
        });
        const borrowed = useDataAdapter(owner);
        expectTypeOf(borrowed).toEqualTypeOf<typeof owner>();
        const bound = DataAdapter({ adapter: borrowed });
        type Slot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
        expectTypeOf<Slot['fields']>().toEqualTypeOf<DataAdapterSlot<typeof props>['fields']>();
        // @ts-expect-error A source policy is not a frontend prop.
        const strictProps: DataAdapterProps = { source: 'People', strictLiteralSearch: true };
        // @ts-expect-error A source policy is not a composable option.
        const strictOptions: DataAdapterOptions = { strictLiteralSearch: false };
        // @ts-expect-error A source policy cannot be added to a borrowed view.
        const boundProps: DataAdapterProps = { adapter: borrowed, literalSearchPolicy: true };
        void strictProps;
        void strictOptions;
        void boundProps;
    };
    void inspect;
});
