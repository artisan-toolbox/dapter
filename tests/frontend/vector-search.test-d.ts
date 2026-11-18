import { expectTypeOf, it } from 'vite-plus/test';
import type { AdapterDefinition, DataAdapterHandle, VectorPolicy } from '@artisan-toolbox/dapter';

type VectorDefinition = Omit<AdapterDefinition, 'fields'> & {
    readonly fields: {
        readonly embedding: {
            readonly search: {
                readonly valueType: 'vector';
                readonly matchTypes: readonly ['vector'];
                readonly vector: VectorPolicy;
            };
            readonly order: null;
            readonly filters: null;
        };
    };
};
declare const adapter: DataAdapterHandle<unknown, VectorDefinition>;
it('preserves prepared vector inputs through a decoded public handle', () => {
    const inspect = () => {
        void adapter.fields?.embedding?.search.call({ value: [0, 1, 0], matchType: 'vector' });
        // @ts-expect-error A prepared vector search cannot accept text.
        void adapter.fields?.embedding?.search.call({ value: 'text', matchType: 'vector' });
        // @ts-expect-error A vector-only field cannot select a scalar operator.
        void adapter.fields?.embedding?.search.call({ value: [0, 1, 0], matchType: 'contains' });
        expectTypeOf(adapter.fields?.embedding?.search.current?.value).toEqualTypeOf<
            readonly number[] | undefined
        >();
    };
    void inspect;
});
