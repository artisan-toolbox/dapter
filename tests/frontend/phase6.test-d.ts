import { expectTypeOf, it } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type AdapterDefinition,
    type DataAdapterHandle,
    type DataAdapterSlot,
    type FullTextPolicy,
    type LocalePolicy,
    type ReadonlyDeep,
} from '@artisan-toolbox/dapter';
import { fullText, locale } from './phase6-fixtures.js';

function decodeDefinition(value: ReadonlyDeep<AdapterDefinition>) {
    return {
        ...value,
        fields: {
            title: {
                search: { valueType: 'string', matchTypes: ['full_text'], fullText },
                order: null,
                filters: null,
            },
            label: {
                search: null,
                order: { directions: ['asc'], sortTypes: ['locale'], locale },
                filters: null,
            },
            status: {
                search: null,
                order: null,
                filters: { selected: { values: [null, false, 0, '0', 'active'], multiple: false } },
            },
        },
        global: {
            ...value.global,
            multi_order: false,
            search: {
                valueType: 'string',
                targets: { title: { valueType: 'string', matchType: 'full_text', fullText } },
            },
        },
    } as const satisfies AdapterDefinition;
}
function decodeRow(_value: unknown) {
    return { record: { id: 1, title: 'Resource title' }, nullable: null };
}
const props = { source: 'Documents', protocol: 2, decodeDefinition, decodeRow } as const;
it('preserves exact policy, field, finite and nullable inference independently of Resource rows', () => {
    const inspect = (slot: DataAdapterSlot<typeof props>) => {
        expectTypeOf(slot.fields?.title?.search.available.fullText).toEqualTypeOf<
            typeof fullText | undefined
        >();
        expectTypeOf(slot.fields?.label?.order.available.locale).toEqualTypeOf<
            typeof locale | undefined
        >();
        expectTypeOf(slot.fields?.title?.order).toEqualTypeOf<null | undefined>();
        expectTypeOf(slot.fields?.label?.search).toEqualTypeOf<null | undefined>();
        expectTypeOf(slot.fields?.status?.filters.selected?.current).toEqualTypeOf<
            { readonly value: null | false | 0 | '0' | 'active' } | null | undefined
        >();
        void slot.fields?.title?.search.call({ value: 'running cats', matchType: 'full_text' });
        void slot.fields?.label?.order.call({ direction: 'asc', sortType: 'locale' });
        void slot.fields?.status?.filters.selected?.call({ value: null });
        void slot.fields?.status?.filters.selected?.call({ value: false });
        void slot.fields?.status?.filters.selected?.call({ value: 0 });
        void slot.fields?.status?.filters.selected?.call({ value: '0' });
        void slot.fields?.status?.filters.selected?.clear();
        void slot.global?.search.call({ value: 'plain text' });
        // @ts-expect-error Full-text cannot broaden a field to a literal operator.
        void slot.fields?.title?.search.call({ value: 'cat', matchType: 'contains' });
        // @ts-expect-error Full-text plain input remains a string.
        void slot.fields?.title?.search.call({ value: 1, matchType: 'full_text' });
        // @ts-expect-error A finite locale field cannot select native order.
        void slot.fields?.label?.order.call({ direction: 'asc', sortType: 'native' });
        // @ts-expect-error Ordering directions remain field-specific.
        void slot.fields?.label?.order.call({ direction: 'desc', sortType: 'locale' });
        // @ts-expect-error Null has no direct operation input meaning.
        void slot.fields?.status?.filters.selected?.call(null);
        // @ts-expect-error Finite scalar operands do not widen to all strings.
        void slot.fields?.status?.filters.selected?.call({ value: 'private' });
        // @ts-expect-error Projected Resource keys do not authorize operations.
        void slot.fields?.record;
        // @ts-expect-error Removed fields may be absent after permission checks.
        void slot.fields?.title.search;
        void slot.fields?.title?.search.call({
            value: 'cat',
            matchType: 'full_text',
            // @ts-expect-error Readers cannot select the full-text configuration.
            configuration: 'pg_catalog.simple',
        });
        // @ts-expect-error Fixed global targets cannot be replaced in a request.
        void slot.global?.search.call({ value: 'cat', targets: ['title'] });
        void slot.fields?.label?.order.call({
            direction: 'asc',
            sortType: 'locale',
            // @ts-expect-error Locale policy is not an ordering input.
            locale: 'en_US',
        });
        if (slot.ready) {
            expectTypeOf(slot.data[0]?.record.title).toEqualTypeOf<string | undefined>();
            expectTypeOf(slot.data[0]?.nullable).toEqualTypeOf<null | undefined>();
            // @ts-expect-error Resource projection does not invent a title at the row root.
            void slot.data[0]?.title;
            // @ts-expect-error Decoded records remain deeply readonly.
            slot.data[0]!.record.title = 'changed';
        }
    };
    void inspect;
});
it('retains policy literals through borrowed handles and bound slots', () => {
    const inspect = () => {
        const handle = useDataAdapter('Documents', { protocol: 2, decodeDefinition, decodeRow });
        const borrowed = useDataAdapter(handle);
        expectTypeOf(borrowed).toEqualTypeOf<
            DataAdapterHandle<ReturnType<typeof decodeRow>, ReturnType<typeof decodeDefinition>>
        >();
        const bound = DataAdapter({ adapter: borrowed });
        type Slot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
        expectTypeOf<Slot['fields']>().toEqualTypeOf<DataAdapterSlot<typeof props>['fields']>();
        const definitionOnly = useDataAdapter('Documents', { protocol: 2, decodeDefinition });
        if (definitionOnly.ready) {
            expectTypeOf(definitionOnly.data).toEqualTypeOf<readonly unknown[]>();
            // @ts-expect-error A comparison definition never defines Resource rows.
            void definitionOnly.data[0]?.record;
        }
        const exportedFullText: FullTextPolicy = fullText;
        const exportedLocale: LocalePolicy = locale;
        void exportedFullText;
        void exportedLocale;
    };
    void inspect;
});
