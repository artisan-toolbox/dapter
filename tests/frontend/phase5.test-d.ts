import { expectTypeOf, it } from 'vite-plus/test';
import { h, shallowRef } from 'vue';
import {
    DataAdapter,
    useDataAdapter,
    type AsyncOutcome,
    type InfiniteControls,
    type InfiniteOptions,
} from '@artisan-toolbox/dapter';
it('exposes readonly controls, inferred decoded rows and v2-only creation options', () => {
    const inspect = () => {
        const root = shallowRef<HTMLElement | null>(null);
        const options: InfiniteOptions = { root, sentinel: () => root.value, axis: 'x' };
        const owner = useDataAdapter('Feed', {
            protocol: 2,
            paging: 'source',
            infinite: options,
            decodeRow: (_value: unknown) => ({ title: 'Item' }),
        });
        expectTypeOf(owner.infinite).toEqualTypeOf<InfiniteControls | null>();
        expectTypeOf(owner.infinite?.append()).toEqualTypeOf<Promise<AsyncOutcome> | undefined>();
        if (owner.ready) expectTypeOf(owner.data[0]?.title).toEqualTypeOf<string | undefined>();
        useDataAdapter('Legacy', { infinite: false });
        DataAdapter({ source: 'Legacy', infinite: false });
        DataAdapter({ source: 'Legacy', protocol: 1, infinite: false });
        h(
            DataAdapter<{ source: string }>,
            { source: 'Legacy', infinite: false },
            {
                default: () => h('output'),
            },
        );
        useDataAdapter('Offset', { protocol: 2, infinite: true });
        const enabled = Math.random() > 0.5;
        useDataAdapter('Feed', { protocol: 2, infinite: enabled });
        DataAdapter({ source: 'Feed', protocol: 2, infinite: enabled });
        const component = DataAdapter({ adapter: owner });
        type Slot = Parameters<NonNullable<typeof component.__ctx>['slots']['default']>[0];
        expectTypeOf<Slot['infinite']>().toEqualTypeOf<InfiniteControls | null>();
        // @ts-expect-error Infinite loading requires explicit protocol 2.
        useDataAdapter('Feed', { infinite: true });
        // @ts-expect-error A dynamic boolean may enable infinite loading and requires protocol 2.
        useDataAdapter('Feed', { infinite: enabled });
        // @ts-expect-error Component booleans also require protocol 2 when they may enable loading.
        DataAdapter({ source: 'Feed', infinite: enabled });
        // @ts-expect-error Literal active component options require protocol 2.
        DataAdapter({ source: 'Feed', infinite: true });
        // @ts-expect-error Observation also requires explicit protocol 2.
        DataAdapter({ source: 'Feed', infinite: options });
        // @ts-expect-error Bindings cannot override immutable creator options.
        DataAdapter({ adapter: owner, infinite: false });
        // @ts-expect-error Controls are readonly.
        if (owner.infinite) owner.infinite.status = 'idle';
        // @ts-expect-error Axis names are closed.
        useDataAdapter('Feed', { protocol: 2, infinite: { root, sentinel: root, axis: 'z' } });
    };
    void inspect;
});
