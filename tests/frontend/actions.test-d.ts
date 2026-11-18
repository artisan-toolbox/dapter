import { expectTypeOf, it } from 'vite-plus/test';
import { ref } from 'vue';
import {
    DataAdapter,
    defineActions,
    useDataAdapter,
    type DataAdapterHandle,
    type ActionDefinitions,
    type AdapterDefinition,
    type WriteOutcome,
} from '@artisan-toolbox/dapter';
import type { Page, VisitOptions, UseHttpSubmitOptions } from '@inertiajs/core';
import type { ActionVisitOptions, ActionHttpOptions } from '@artisan-toolbox/dapter';
const decodeInput = (input: unknown) => {
    if (
        typeof input !== 'object' ||
        input === null ||
        !('id' in input) ||
        typeof input.id !== 'number'
    )
        throw new Error('Invalid input');
    return { id: input.id };
};
const decodeResult = (_input: unknown) => ({ saved: true }) as const;
const actionDefinitions = defineActions({
    save: { transports: ['http', 'visit'], decodeInput, decodeResult },
    background: { transports: ['http'], decodeResult },
    navigate: { transports: ['visit'], decodeInput },
    raw: { transports: ['http'] },
});
it('infers input and result independently through sources, refs, getters, slots, and genuine handles', () => {
    expectTypeOf<ActionVisitOptions>().toEqualTypeOf<
        Omit<VisitOptions, 'url' | 'method' | 'data' | 'headers'>
    >();
    expectTypeOf<ActionHttpOptions>().toEqualTypeOf<
        Omit<UseHttpSubmitOptions<unknown>, 'headers' | 'optimistic'>
    >();
    const handle = useDataAdapter('Products', { actionDefinitions });
    const borrowed = useDataAdapter(handle),
        reactive = useDataAdapter(ref('Products'), { id: 'reactive', actionDefinitions }),
        getter = useDataAdapter(() => 'Products', { id: 'getter', actionDefinitions });
    expectTypeOf(borrowed.actions).toEqualTypeOf(handle.actions);
    expectTypeOf(reactive.actions).toEqualTypeOf(getter.actions);
    const source = DataAdapter({ source: 'Products', actionDefinitions }),
        bound = DataAdapter({ adapter: handle });
    type SourceSlot = Parameters<NonNullable<typeof source.__ctx>['slots']['default']>[0];
    type BoundSlot = Parameters<NonNullable<typeof bound.__ctx>['slots']['default']>[0];
    expectTypeOf<SourceSlot['actions']>().toEqualTypeOf(handle.actions);
    expectTypeOf<BoundSlot['actions']>().toEqualTypeOf(handle.actions);
    const explicit: DataAdapterHandle<unknown, AdapterDefinition, typeof actionDefinitions> =
        handle;
    void explicit;
    const verify = async () => {
        const result = await handle.actions?.http('save', { id: 1 });
        if (result?.status === 'completed')
            expectTypeOf(result.data).toEqualTypeOf<{ readonly saved: true }>();
        const independent = await handle.actions?.http('background', { anything: true });
        if (independent?.status === 'completed')
            expectTypeOf(independent.data).toEqualTypeOf<{ readonly saved: true }>();
        const raw = await handle.actions?.http('raw', {});
        if (raw?.status === 'completed') expectTypeOf(raw.data).toEqualTypeOf<unknown>();
        const native = handle.actions?.visit(
            'navigate',
            { id: 1 },
            {
                onSuccess(page) {
                    expectTypeOf(page).toEqualTypeOf<Page>();
                },
            },
        );
        expectTypeOf(native).toEqualTypeOf<void | undefined>();
        await handle.actions?.http(
            'save',
            { id: 1 },
            {
                onSuccess(data) {
                    expectTypeOf(data).toEqualTypeOf<unknown>();
                },
            },
        );
        // @ts-expect-error Inputs come from the input decoder.
        await handle.actions?.http('save', { id: '1' });
        // @ts-expect-error Known definitions restrict action identifiers.
        await handle.actions?.http('missing', {});
        // @ts-expect-error Visit-only actions do not allow HTTP.
        await handle.actions?.http('navigate', { id: 1 });
        // @ts-expect-error HTTP-only actions do not allow visits.
        handle.actions?.visit('background', {});
        // @ts-expect-error Generated action URL cannot be overridden.
        handle.actions?.visit('save', { id: 1 }, { url: '/other' });
        // @ts-expect-error Method belongs to authorized metadata.
        handle.actions?.visit('save', { id: 1 }, { method: 'delete' });
        // @ts-expect-error Payload has its own argument.
        handle.actions?.visit('save', { id: 1 }, { data: {} });
        // @ts-expect-error Reserved headers cannot be overridden.
        handle.actions?.visit('save', { id: 1 }, { headers: {} });
        // @ts-expect-error HTTP has no scroll or history options.
        await handle.actions?.http('save', { id: 1 }, { preserveScroll: true });
        // @ts-expect-error HTTP optimistic rollback is not supported.
        await handle.actions?.http('save', { id: 1 }, { optimistic: () => ({}) });
        // @ts-expect-error HTTP headers are package-owned.
        await handle.actions?.http('save', { id: 1 }, { headers: {} });
    };
    // @ts-expect-error Bound components cannot replace action definitions.
    DataAdapter({ adapter: handle, actionDefinitions });
    // @ts-expect-error Free action generics cannot invent application decoders.
    DataAdapter<{ source: string }, unknown, AdapterDefinition, typeof actionDefinitions>({
        source: 'Products',
    });
    void verify;
});
it('preserves explicit row generics without inventing action result types', () => {
    const handle = useDataAdapter<{ id: number }>('Products', { decodeRow: () => ({ id: 1 }) });
    expectTypeOf(handle.actions?.http('anything', {})).toEqualTypeOf<
        Promise<WriteOutcome> | undefined
    >();
    const definitions: ActionDefinitions = actionDefinitions;
    void definitions;
});
