import { HttpNetworkError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, shallowRef, watch, type ComputedRef } from 'vue';
import {
    freeze,
    intent,
    parseFailure,
    parsePage,
    protocolError,
    validationFailure,
    type AcceptedState,
    type AdapterError,
    type Page,
    type ReadonlyDeep,
    type RequestedState,
} from './protocol.js';
export type { AdapterError, ReadonlyDeep } from './protocol.js';
export type SourceAdapterProps = {
    source: string;
    id?: string | undefined;
    decodeRow?: ((value: unknown) => unknown) | undefined;
    adapter?: never;
};
export type DataAdapterProps =
    | SourceAdapterProps
    | {
          adapter: DataAdapterHandle<unknown>;
          source?: never;
          id?: never;
          decodeRow?: never;
      };
type RowOf<P extends DataAdapterProps> = P extends { adapter: DataAdapterHandle<infer Row> }
    ? Row
    : P extends { decodeRow: (value: unknown) => infer Row }
      ? Row
      : unknown;
export type AsyncOutcome =
    | { readonly status: 'success' }
    | { readonly status: 'superseded' }
    | { readonly status: 'failure'; readonly error: AdapterError };
export type OffsetPaging = {
    readonly mode: 'offset';
    readonly loading: boolean;
    readonly empty: boolean;
    readonly current: number | null;
    readonly pages: number | null;
    readonly total: number | null;
    readonly filtered: number | null;
    readonly has_previous: boolean;
    readonly has_next: boolean;
    readonly per_page: {
        readonly value: number | null;
        readonly options: readonly number[];
        readonly setValue: (value: number) => Promise<AsyncOutcome>;
    };
    readonly goToFirst: () => Promise<AsyncOutcome>;
    readonly goToPrevious: () => Promise<AsyncOutcome>;
    readonly goToNext: () => Promise<AsyncOutcome>;
    readonly goToPage: (value: number) => Promise<AsyncOutcome>;
    readonly goToLast: () => Promise<AsyncOutcome>;
    readonly reload: () => Promise<AsyncOutcome>;
};
export type DataAdapterSlot<P extends DataAdapterProps = DataAdapterProps> = DataAdapterHandle<
    RowOf<P>
>;
export type DataAdapterHandle<Row = unknown> = {
    readonly status: 'idle' | 'loading' | 'success' | 'error';
    readonly error: AdapterError | null;
    readonly requested: RequestedState | null;
    readonly accepted: AcceptedState | null;
    readonly paging: OffsetPaging;
} & (
    | { readonly ready: false; readonly data: readonly [] }
    | { readonly ready: true; readonly data: readonly ReadonlyDeep<Row>[] }
);
function decode<P extends SourceAdapterProps>(
    data: readonly unknown[],
    props: P,
): readonly ReadonlyDeep<RowOf<P>>[];
function decode(data: readonly unknown[], props: SourceAdapterProps): readonly unknown[] {
    return freeze(data.map((row) => (props.decodeRow ? props.decodeRow(row) : row)));
}
type Accepted<P extends SourceAdapterProps> = {
    page: Page;
    data: readonly ReadonlyDeep<RowOf<P>>[];
};
export function createController<P extends SourceAdapterProps>(
    props: P,
): {
    state: ComputedRef<DataAdapterSlot<P>>;
    start: () => void;
    dispose: () => void;
} {
    const accepted = shallowRef<Accepted<P> | null>(null);
    const requested = shallowRef<RequestedState | null>(null);
    const error = shallowRef<AdapterError | null>(null);
    const status = shallowRef<DataAdapterSlot<P>['status']>('idle');
    let generation = 0;
    let mounted = false;
    let pending: { key: string; cancel: () => void; promise: Promise<AsyncOutcome> } | null = null;
    const success = (): Promise<AsyncOutcome> => Promise.resolve({ status: 'success' });
    function rejectLocal(): Promise<AsyncOutcome> {
        return Promise.resolve({
            status: 'failure',
            error: {
                kind: 'validation',
                code: 'invalid_paging',
                message: 'Choose an available page and page size.',
            },
        });
    }
    function invalidate(): void {
        generation++;
        const previous = pending;
        pending = null;
        previous?.cancel();
    }
    function submit(state: RequestedState | null, force = false): Promise<AsyncOutcome> {
        if (!mounted) return Promise.resolve({ status: 'superseded' });
        const key = JSON.stringify(state);
        if (!force && pending?.key === key) return pending.promise;
        if (
            !force &&
            !pending &&
            status.value === 'success' &&
            JSON.stringify(requested.value) === key
        )
            return success();
        invalidate();
        const active = generation;
        requested.value = freeze(state);
        status.value = 'loading';
        error.value = null;
        const form = useHttp<{ version: number; state?: RequestedState }, unknown>(
            state ? { version: 1, state } : { version: 1 },
        ).withAllErrors();
        let rejected: AdapterError | null = null;
        let received = false;
        const promise: Promise<AsyncOutcome> = Promise.resolve().then(async () => {
            if (active !== generation || !mounted) return { status: 'superseded' };
            try {
                const response = await form.post(route('dapter.data', { source: props.source }), {
                    onError: (errors: unknown) => {
                        rejected = validationFailure(errors);
                    },
                    onHttpException: (response: {
                        readonly data: string;
                        readonly status: number;
                    }) => {
                        try {
                            rejected = parseFailure(JSON.parse(response.data));
                        } catch {
                            rejected = protocolError();
                        }
                        if (!rejected) rejected = protocolError();
                        if ([401, 403, 404].includes(response.status))
                            rejected = {
                                kind: 'access',
                                code: 'access_denied',
                                message: 'Access to this data source was denied.',
                            };
                    },
                });
                if (active !== generation || !mounted) return { status: 'superseded' };
                if (rejected) throw rejected;
                received = true;
                const page = parsePage(response);
                const data = decode(page.data, props);
                if (active !== generation || !mounted) return { status: 'superseded' };
                accepted.value = { page, data };
                requested.value = page.requested;
                status.value = 'success';
                error.value = null;
                return { status: 'success' };
            } catch (cause) {
                if (active !== generation || !mounted) return { status: 'superseded' };
                const failure =
                    rejected ??
                    (!received && cause instanceof HttpNetworkError
                        ? ({
                              kind: 'network',
                              code: 'request_failed',
                              message: 'The data source could not be reached.',
                          } satisfies AdapterError)
                        : protocolError());
                if (failure.kind === 'access' || failure.kind === 'protocol') accepted.value = null;
                error.value = Object.freeze(failure);
                status.value = 'error';
                return { status: 'failure', error: failure };
            } finally {
                if (active === generation) pending = null;
            }
        });
        pending = { key, cancel: () => form.cancel(), promise };
        return promise;
    }
    function goToPage(value: number): Promise<AsyncOutcome> {
        const page = accepted.value?.page;
        if (!page || !Number.isSafeInteger(value) || value < 1 || value > page.paging.pages)
            return rejectLocal();
        return submit(intent(value, requested.value?.paging.per_page ?? page.paging.per_page));
    }
    function setValue(value: number): Promise<AsyncOutcome> {
        const page = accepted.value?.page;
        if (!page || !page.options.includes(value)) return rejectLocal();
        if ((requested.value?.paging.per_page ?? page.paging.per_page) === value)
            return submit(requested.value ?? page.requested);
        return submit(intent(1, value));
    }
    const reload = (): Promise<AsyncOutcome> => submit(requested.value, true);
    const goToFirst = (): Promise<AsyncOutcome> => (accepted.value ? goToPage(1) : success());
    const goToPrevious = (): Promise<AsyncOutcome> =>
        (requested.value?.paging.current ?? 1) > 1
            ? goToPage((requested.value?.paging.current ?? 1) - 1)
            : success();
    const goToNext = (): Promise<AsyncOutcome> =>
        accepted.value && (requested.value?.paging.current ?? 1) < accepted.value.page.paging.pages
            ? goToPage((requested.value?.paging.current ?? 1) + 1)
            : success();
    const goToLast = (): Promise<AsyncOutcome> =>
        accepted.value ? goToPage(accepted.value.page.paging.pages) : success();
    const slot = computed<DataAdapterSlot<P>>(() => {
        const result = accepted.value;
        const paging: OffsetPaging = {
            mode: 'offset',
            loading: status.value === 'loading',
            empty: !!result && result.page.data.length === 0,
            current: result?.page.paging.current ?? null,
            pages: result?.page.paging.pages ?? null,
            total: result?.page.paging.total ?? null,
            filtered: result?.page.paging.filtered ?? null,
            has_previous: result?.page.paging.has_previous ?? false,
            has_next: result?.page.paging.has_next ?? false,
            per_page: {
                value: result?.page.paging.per_page ?? null,
                options: result?.page.options ?? Object.freeze([]),
                setValue,
            },
            goToPage,
            goToFirst,
            goToPrevious,
            goToNext,
            goToLast,
            reload,
        };
        Object.freeze(paging.per_page);
        Object.freeze(paging);
        const common = {
            status: status.value,
            error: error.value,
            requested: requested.value,
            accepted: result?.page.state ?? null,
            paging,
        };
        return result
            ? Object.freeze({ ...common, ready: true, data: result.data })
            : Object.freeze({ ...common, ready: false, data: Object.freeze<[]>([]) });
    });
    watch(
        () => [props.source, props.decodeRow],
        () => {
            invalidate();
            accepted.value = null;
            requested.value = null;
            error.value = null;
            status.value = 'idle';
            if (mounted) void submit(null);
        },
        { flush: 'sync' },
    );
    return {
        state: slot,
        start() {
            if (mounted) return;
            mounted = true;
            void submit(null);
        },
        dispose() {
            mounted = false;
            invalidate();
            accepted.value = null;
            requested.value = null;
            error.value = null;
            status.value = 'idle';
        },
    };
}
