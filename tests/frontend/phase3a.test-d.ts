import { expectTypeOf, test } from 'vite-plus/test';
import {
    DataAdapter,
    useDataAdapter,
    type DapterEcho,
    type IntegrationError,
    type AsyncOutcome,
} from '@artisan-toolbox/dapter';
const echo: DapterEcho = {
    private: () => ({ listen() {}, stopListening() {}, error() {} }),
    channel: () => ({ listen() {}, stopListening() {}, error() {} }),
};
test('live and polling ownership remains inferred and readonly', () => {
    const owner = useDataAdapter('Products', {
        protocol: 2,
        echo,
        polling: { interval: 0.5 },
        decodeRow: (_value: unknown) => ({ label: 'projected' }),
    });
    expectTypeOf(owner.integrationError).toEqualTypeOf<IntegrationError | null>();
    expectTypeOf(owner.resetContext()).toEqualTypeOf<Promise<AsyncOutcome>>();
    expectTypeOf(owner.restartPolling()).toEqualTypeOf<void>();
    if (owner.ready) {
        expectTypeOf(owner.data[0]?.label).toEqualTypeOf<string | undefined>();
        const row = owner.data[0];
        if (row) {
            // @ts-expect-error Accepted projected rows remain readonly.
            row.label = 'mutated';
        }
    }
    if (owner.metadata?.records.mode === 'revisioned') {
        expectTypeOf(owner.metadata.records.rows[0]?.revision).toEqualTypeOf<string | undefined>();
        const record = owner.metadata.records.rows[0];
        if (record) {
            // @ts-expect-error Aligned metadata remains readonly.
            record.revision = '2';
        }
    }
    DataAdapter({ adapter: owner });
    // @ts-expect-error Bound views cannot override Echo.
    DataAdapter({ adapter: owner, echo });
    // @ts-expect-error Even false polling belongs to the owner.
    DataAdapter({ adapter: owner, polling: false });
    // @ts-expect-error Echo requires explicit protocol 2.
    useDataAdapter('Products', { echo });
    // @ts-expect-error Polling requires explicit protocol 2.
    useDataAdapter('Products', { polling: { interval: 100 } });
    // @ts-expect-error Protocol 1 cannot enable polling.
    useDataAdapter('Products', { protocol: 1, polling: { interval: 100 } });
    // @ts-expect-error Component Echo requires explicit protocol 2.
    DataAdapter({ source: 'Products', echo });
    // @ts-expect-error Component polling requires explicit protocol 2.
    DataAdapter({ source: 'Products', polling: { interval: 100 } });
    useDataAdapter('Products', { polling: false });
    DataAdapter({ source: 'Products', protocol: 2, echo, polling: { interval: 100 } });
});
