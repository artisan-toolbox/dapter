import { observeInfinite } from './infinite-observer.js';
import {
    acceptWindow,
    firstPosition,
    nextPosition,
    replaceOccurrences,
    type LoadedWindow,
} from './window.js';
import {
    decodeRows,
    parseUpdate,
    sameChannel,
    type RowUpdate,
    type ChannelDescriptor,
} from './records.js';
import { subscribe, type IntegrationError } from './realtime.js';
import { createActionController } from './actions.js';
import { BrowserKeyConflict, createBrowser } from './browser.js';
import { HttpNetworkError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, nextTick, shallowRef, toValue, watch, type ComputedRef } from 'vue';
import type {
    AsyncOutcome,
    DataAdapterSlot,
    SourceAdapterProps,
    RowOf,
    DefinitionOf,
    FieldsView,
    FieldView,
    OffsetPaging,
    SourcePaging,
    AnyDataAdapterHandle,
    GlobalView,
} from './adapter.js';
import {
    freeze,
    dictionary,
    parseFailure,
    protocolError,
    validationFailure,
    type AdapterError,
    type ReadonlyDeep,
} from './protocol.js';
import {
    parsePageV2,
    parseDefinition,
    assertNarrowing,
    parseRequestedV2,
    type AdapterDefinition,
    type SourceRequestedStateV2 as RequestedStateV2,
    type PageV2,
} from './protocol-v2.js';
import { reduceRequested, stateKey, type StateCommand } from './requested-state.js';

type Result<P extends SourceAdapterProps> = {
    page: PageV2;
    window: LoadedWindow | null;
    data: readonly ReadonlyDeep<RowOf<P>>[];
    definition: ReadonlyDeep<DefinitionOf<P>>;
};
function decode<P extends SourceAdapterProps>(page: PageV2, props: P): Result<P>;
function decode(
    page: PageV2,
    props: SourceAdapterProps,
): {
    page: PageV2;
    window: LoadedWindow | null;
    data: readonly unknown[];
    definition: ReadonlyDeep<AdapterDefinition>;
} {
    const raw = props.decodeDefinition
        ? props.decodeDefinition(freeze(page.definition))
        : page.definition;
    const definition = parseDefinition(raw);
    assertNarrowing(page.definition, definition);
    parseRequestedV2(page.requested, definition);
    return {
        page,
        window: null,
        data: decodeRows(page.data, props.decodeRow),
        definition,
    };
}
function project<D extends AdapterDefinition>(
    definition: ReadonlyDeep<D>,
    requested: RequestedStateV2 | null,
    apply: (command: StateCommand) => Promise<AsyncOutcome>,
): FieldsView<D>;
function project(
    definition: ReadonlyDeep<AdapterDefinition>,
    requested: RequestedStateV2 | null,
    apply: (command: StateCommand) => Promise<AsyncOutcome>,
): FieldsView {
    const fields = dictionary<FieldView>();
    for (const [name, field] of Object.entries(definition.fields)) {
        if (!field) continue;
        const selection = requested?.fields[name];
        const term =
            requested?.order.kind === 'fields'
                ? requested.order.terms.find((term) => term.field === name)
                : undefined;
        const mutableFilters = dictionary<NonNullable<NonNullable<FieldView['filters']>[string]>>();
        for (const [id, available] of Object.entries(field.filters ?? {})) {
            if (!available) continue;
            mutableFilters[id] = {
                available,
                current: selection?.filters?.[id] ?? null,
                call: (value, options) =>
                    apply({
                        kind: 'filter',
                        field: name,
                        id,
                        value,
                        ...(options ? { options } : {}),
                    }),
                clear: (options) =>
                    apply({
                        kind: 'filter',
                        field: name,
                        id,
                        value: null,
                        clear: true,
                        ...(options ? { options } : {}),
                    }),
            };
        }
        fields[name] = {
            search: field.search
                ? {
                      available: field.search,
                      current: selection?.search ?? null,
                      call: (value, options) =>
                          apply({
                              kind: 'search',
                              field: name,
                              value,
                              ...(options ? { options } : {}),
                          }),
                      clear: (options) =>
                          apply({
                              kind: 'search',
                              field: name,
                              value: null,
                              clear: true,
                              ...(options ? { options } : {}),
                          }),
                  }
                : null,
            order: field.order
                ? {
                      available: field.order,
                      current: term
                          ? {
                                direction: term.direction,
                                sortType: term.sortType,
                                priority:
                                    requested?.order.kind === 'fields'
                                        ? requested.order.terms.indexOf(term) + 1
                                        : 1,
                            }
                          : null,
                      call: (value, options) =>
                          apply({
                              kind: 'order',
                              field: name,
                              value,
                              ...(options ? { options } : {}),
                          }),
                      clear: (options) =>
                          apply({
                              kind: 'order',
                              field: name,
                              value: null,
                              clear: true,
                              ...(options ? { options } : {}),
                          }),
                  }
                : null,
            filters: field.filters ? mutableFilters : null,
        };
    }
    return freeze(fields);
}
function projectGlobal<D extends AdapterDefinition>(
    definition: ReadonlyDeep<D>,
    requested: RequestedStateV2 | null,
    apply: (command: StateCommand) => Promise<AsyncOutcome>,
): GlobalView<D>;
function projectGlobal(
    definition: ReadonlyDeep<AdapterDefinition>,
    requested: RequestedStateV2 | null,
    apply: (command: StateCommand) => Promise<AsyncOutcome>,
): GlobalView {
    const filters = dictionary<NonNullable<NonNullable<GlobalView['filters']>[string]>>();
    for (const [id, available] of Object.entries(definition.global.filters ?? {})) {
        if (!available) continue;
        filters[id] = {
            available,
            current: requested?.global.filters?.[id] ?? null,
            call: (value, options) =>
                apply({ kind: 'globalFilter', id, value, ...(options ? { options } : {}) }),
            clear: (options) =>
                apply({
                    kind: 'globalFilter',
                    id,
                    value: null,
                    clear: true,
                    ...(options ? { options } : {}),
                }),
        };
    }
    return freeze({
        ...definition.global,
        search: definition.global.search
            ? {
                  available: definition.global.search,
                  current: requested?.global.search ?? null,
                  call: (
                      value: { readonly value: string },
                      options?: import('./requested-state.js').StateOptions,
                  ) => apply({ kind: 'globalSearch', value, ...(options ? { options } : {}) }),
                  clear: (options?: import('./requested-state.js').StateOptions) =>
                      apply({
                          kind: 'globalSearch',
                          value: null,
                          clear: true,
                          ...(options ? { options } : {}),
                      }),
              }
            : null,
        filters: definition.global.filters ? filters : null,
        order: definition.global.order
            ? {
                  available: definition.global.order,
                  current: requested?.order.kind === 'preset' ? { id: requested.order.id } : null,
                  call: (
                      value: { readonly id: string },
                      options?: import('./requested-state.js').StateOptions,
                  ) => apply({ kind: 'preset', value, ...(options ? { options } : {}) }),
                  clear: (options?: import('./requested-state.js').StateOptions) =>
                      apply({
                          kind: 'preset',
                          value: null,
                          clear: true,
                          ...(options ? { options } : {}),
                      }),
              }
            : null,
    });
}
export function createV2Controller<P extends SourceAdapterProps>(
    props: P,
): { state: ComputedRef<DataAdapterSlot<P>>; start: () => void; dispose: () => void };
export function createV2Controller(props: SourceAdapterProps): {
    state: ComputedRef<AnyDataAdapterHandle>;
    start: () => void;
    dispose: () => void;
} {
    if (
        props.searchDebounce !== undefined &&
        (!Number.isSafeInteger(props.searchDebounce) || props.searchDebounce < 0)
    )
        throw new Error('searchDebounce must be a nonnegative integer.');
    const session = shallowRef<{
        accepted: Result<SourceAdapterProps> | null;
        definitionContext: {
            definition: Result<SourceAdapterProps>['definition'];
            pages: number | null;
        } | null;
        requested: RequestedStateV2 | null;
        error: AdapterError | null;
        status: AnyDataAdapterHandle['status'];
    }>({ accepted: null, definitionContext: null, requested: null, error: null, status: 'idle' });
    const infiniteEnabled = props.infinite !== undefined && props.infinite !== false;
    const infiniteState = shallowRef<'idle' | 'loading' | 'paused' | 'exhausted'>('idle');
    let appendOperation: {
        promise: Promise<AsyncOutcome>;
        settle: (outcome: AsyncOutcome) => void;
    } | null = null;
    let restartInfinite = false;
    const observedReady = shallowRef(false);
    let mounted = false,
        generation = 0,
        epoch = 0;
    let pending: {
        key: string;
        explicit: boolean;
        reason: Parameters<ReturnType<typeof createBrowser>['publish']>[1];
        promise: Promise<AsyncOutcome>;
        settle: (outcome: AsyncOutcome) => void;
        cancel: () => void;
        timer: ReturnType<typeof setTimeout> | null;
        flush: () => void;
    } | null = null;
    const integrationError = shallowRef<IntegrationError | null>(null);
    let work:
        | { kind: 'idle' }
        | { kind: 'debounced' | 'requesting' | 'publishing'; generation: number } = {
        kind: 'idle',
    };
    let descriptor: ChannelDescriptor | null = null;
    let detach: (() => void) | null = null;
    let suspended = false;
    let liveVersion = 0;
    let buffer: { update: RowUpdate; decoded: readonly unknown[] }[] = [];
    let bufferBytes = 0;
    const eventIds = new Set<string>();
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let pollingPaused = false;
    const pollingOption = props.polling;
    const polling = pollingOption && Object.freeze({ ...pollingOption });
    if (
        polling !== undefined &&
        polling !== false &&
        (typeof polling !== 'object' ||
            polling === null ||
            Object.keys(polling).length !== 1 ||
            !Object.hasOwn(polling, 'interval') ||
            typeof polling.interval !== 'number' ||
            !Number.isFinite(polling.interval) ||
            polling.interval <= 0 ||
            polling.interval > 2147483647)
    )
        throw new Error('polling requires a positive interval at most 2147483647 milliseconds.');
    function cancelPoll(): void {
        if (pollTimer !== null) clearTimeout(pollTimer);
        pollTimer = null;
    }
    function eligible(): boolean {
        return (
            mounted &&
            !pollingPaused &&
            !pending &&
            work.kind === 'idle' &&
            (typeof document === 'undefined' || document.visibilityState !== 'hidden') &&
            (typeof navigator === 'undefined' || navigator.onLine !== false)
        );
    }
    function schedulePoll(): void {
        cancelPoll();
        if (!polling || !eligible()) return;
        pollTimer = setTimeout(() => {
            pollTimer = null;
            if (!eligible()) return;
            void submit(
                infiniteEnabled ? firstPosition(session.value.requested) : session.value.requested,
                true,
                0,
                'reload',
                false,
            );
        }, polling.interval);
    }
    function availability(): void {
        schedulePoll();
    }
    function failIntegration(code: IntegrationError['code']): void {
        integrationError.value = Object.freeze({
            kind: 'integration',
            code,
            message: 'The adapter integration could not complete. Retry explicitly to recover.',
        });
    }
    function discardBuffer(): void {
        buffer = [];
        bufferBytes = 0;
    }
    function detachListener(): void {
        liveVersion++;
        const old = detach;
        detach = null;
        old?.();
    }
    function clearLive(scope: 'context' | 'window' = 'context'): void {
        detachListener();
        discardBuffer();
        eventIds.clear();
        if (scope === 'window' && suspended) return;
        descriptor = null;
        suspended = false;
        integrationError.value = null;
    }
    function overflow(): void {
        discardBuffer();
        suspended = true;
        detachListener();
        failIntegration('event_overflow');
    }
    function replace(update: RowUpdate, decoded: readonly unknown[], publish = false): void {
        const result = session.value.accepted;
        if (!result || result.page.metadata.records.mode === 'unidentified') return;
        const current = result.page.metadata.records;
        if (current.mode !== update.records.mode) {
            failIntegration('invalid_event');
            return;
        }
        const incoming = update.records.rows[0];
        if (!incoming) return;
        const replacement = replaceOccurrences(result, update, decoded);
        if (replacement === result) return;
        const active = generation,
            context = epoch;
        if (publish) {
            cancelPoll();
            work = { kind: 'publishing', generation: active };
        }
        session.value = { ...session.value, accepted: replacement };
        if (publish) void settlePublication(Promise.resolve(), active, context);
    }
    function listen(next: ChannelDescriptor | null, explicit: boolean, recover: boolean): void {
        if (!sameChannel(descriptor, next)) {
            clearLive();
            descriptor = next;
        }
        if (explicit && (!suspended || recover)) {
            suspended = false;
            integrationError.value = null;
        }
        if (!descriptor || detach || suspended) return;
        if (!props.echo) {
            failIntegration('echo_missing');
            return;
        }
        const context = epoch,
            version = liveVersion,
            channel = descriptor,
            source = props.source;
        const current = () =>
            mounted &&
            epoch === context &&
            liveVersion === version &&
            sameChannel(descriptor, channel);
        let failed = false;
        try {
            const stop = subscribe(
                props.echo,
                channel,
                (value) => {
                    if (!current() || suspended) return;
                    let update: RowUpdate | null;
                    try {
                        update = parseUpdate(value, source, channel);
                        if (!update || eventIds.has(update.eventId)) return;
                        if (
                            session.value.accepted?.page.metadata.records.mode !==
                            update.records.mode
                        )
                            throw new Error('Mismatched record mode');
                    } catch {
                        failIntegration('invalid_event');
                        return;
                    }
                    let decoded: readonly unknown[];
                    try {
                        decoded = decodeRows(update.data, props.decodeRow);
                    } catch {
                        if (current()) failIntegration('decoder_failed');
                        return;
                    }
                    if (!current() || suspended) return;
                    eventIds.add(update.eventId);
                    if (eventIds.size > 512) {
                        const oldest = eventIds.values().next().value;
                        if (oldest !== undefined) eventIds.delete(oldest);
                    }
                    if (pending || work.kind !== 'idle') {
                        if (buffer.length === 128 || bufferBytes + update.bytes > 1048576) {
                            overflow();
                            return;
                        }
                        buffer.push({ update, decoded });
                        bufferBytes += update.bytes;
                    } else replace(update, decoded, true);
                },
                () => {
                    if (!current()) return;
                    failed = true;
                    suspended = true;
                    discardBuffer();
                    detachListener();
                    failIntegration('subscription_failed');
                },
            );
            if (failed || !current()) stop();
            else detach = stop;
        } catch {
            if (current()) {
                suspended = true;
                detachListener();
                failIntegration('subscription_failed');
            }
        }
    }
    async function settlePublication(
        publication: Promise<void>,
        active: number,
        context: number,
    ): Promise<boolean> {
        const current = () => mounted && generation === active && epoch === context;
        try {
            await publication;
            await nextTick();
            if (!current()) return false;
            let replayedEvents = 0,
                replayedBytes = 0;
            do {
                const replay = buffer;
                replayedEvents += replay.length;
                replayedBytes += bufferBytes;
                discardBuffer();
                if (replayedEvents > 128 || replayedBytes > 1048576) {
                    overflow();
                    break;
                }
                for (const item of replay) {
                    if (!current()) return false;
                    replace(item.update, item.decoded);
                }
                await nextTick();
                if (!current()) return false;
            } while (buffer.length > 0);
            work = { kind: 'idle' };
            schedulePoll();
            return true;
        } catch {
            if (!current()) return false;
            discardBuffer();
            work = { kind: 'idle' };
            pollingPaused = true;
            failIntegration('render_failed');
            if (infiniteEnabled) {
                restartInfinite = true;
                infiniteState.value = 'paused';
            }
            cancelPoll();
            return false;
        }
    }
    function restartPolling(): void {
        if (!polling || !mounted) return;
        pollingPaused = false;
        schedulePoll();
    }
    const actions = createActionController({
        metadata: () => session.value.accepted?.page.actions ?? null,
        definitions: () => props.actionDefinitions,
        active: () => mounted,
        epoch: () => epoch,
    });
    const success = (): Promise<AsyncOutcome> => Promise.resolve({ status: 'success' });
    const superseded = (): Promise<AsyncOutcome> => Promise.resolve({ status: 'superseded' });
    function invalid(): Promise<AsyncOutcome> {
        return Promise.resolve({
            status: 'failure',
            error: {
                kind: 'validation',
                code: 'invalid_state',
                message: 'Choose an available operation, value, page, and page size.',
            },
        });
    }
    function invalidate(resetObservation = true) {
        if (resetObservation) observedReady.value = false;
        const oldAppend = appendOperation;
        appendOperation = null;
        oldAppend?.settle({ status: 'superseded' });
        cancelPoll();
        work = { kind: 'idle' };
        generation++;
        const old = pending;
        pending = null;
        if (old) {
            if (old.timer !== null) clearTimeout(old.timer);
            old.cancel();
            old.settle({ status: 'superseded' });
        }
    }
    function makeBrowser() {
        return createBrowser({
            source: props.source,
            id: props.id,
            enabled: props.url !== false,
            scrollContainer: () =>
                props.scrollContainer === undefined ? null : toValue(props.scrollContainer),
            onRestore(snapshot) {
                invalidate();
                void submit(snapshot, true, 0, 'history');
            },
        });
    }
    let browser = makeBrowser();
    function initial() {
        void submit(null, true, 0, 'initial');
    }

    function submit(
        state: RequestedStateV2 | null,
        force = false,
        delay = 0,
        reason: Parameters<ReturnType<typeof createBrowser>['publish']>[1] = 'replace',
        explicit = true,
        settledOutcome = false,
    ): Promise<AsyncOutcome> {
        if (!mounted) return superseded();
        if (state && props.paging !== 'source' && state.paging.mode !== 'offset') return invalid();
        cancelPoll();
        const key = stateKey(state);
        if (pending?.key === key) {
            if (force) pending.reason = reason;
            if (explicit) pending.explicit = true;
            if (delay === 0 && pending.timer !== null) {
                pending.flush();
            }
            return pending.promise;
        }
        if (
            !force &&
            !pending &&
            session.value.status === 'success' &&
            stateKey(session.value.requested) === key
        ) {
            schedulePoll();
            return success();
        }
        invalidate(reason !== 'append');
        if (infiniteEnabled && reason !== 'append') {
            infiniteState.value = 'idle';
            restartInfinite = false;
            clearLive('window');
            session.value = { ...session.value, accepted: null };
        }
        const active = generation;
        work = { kind: delay > 0 ? 'debounced' : 'requesting', generation: active };
        session.value = {
            ...session.value,
            requested: freeze(state),
            status: 'loading',
            error: null,
        };
        if (!mounted || active !== generation) return superseded();
        let resolve: (outcome: AsyncOutcome) => void = () => {};
        const promise = new Promise<AsyncOutcome>((done) => {
            resolve = done;
        });
        let form: ReturnType<
            typeof useHttp<{ version: number; state?: RequestedStateV2 }, unknown>
        > | null = null;
        if (reason === 'append' || settledOutcome) {
            appendOperation = { promise, settle: resolve };
            infiniteState.value = 'loading';
        }
        function followRead(
            target: RequestedStateV2 | null,
            nextReason: 'initial' | 'history' | 'reset',
        ) {
            pending = null;
            appendOperation = null;
            void submit(target, true, 0, nextReason, explicit, settledOutcome).then(resolve);
        }
        async function run() {
            if (!mounted || active !== generation) return;
            work = { kind: 'requesting', generation: active };
            form = useHttp<{ version: number; state?: RequestedStateV2 }, unknown>(
                state ? { version: 2, state } : { version: 2 },
            ).withAllErrors();
            let rejected: AdapterError | null = null,
                received = false;
            const expectedVersion = state && props.url !== false ? browser.version() : undefined;
            try {
                const response = await form.post(route('dapter.data', { source: props.source }), {
                    ...(props.url !== false
                        ? {
                              headers: {
                                  'X-Dapter-Browser-State': '1',
                                  ...(expectedVersion
                                      ? { 'X-Dapter-State-Version': expectedVersion }
                                      : {}),
                              },
                          }
                        : {}),
                    onError: (errors: unknown) => {
                        rejected = validationFailure(errors, 2);
                    },
                    onHttpException: (response: {
                        readonly data: string;
                        readonly status: number;
                    }) => {
                        try {
                            rejected = parseFailure(JSON.parse(response.data), 2);
                            if (
                                rejected?.kind === 'cursor_expired' &&
                                (response.status !== 409 || state?.paging.mode !== 'cursor')
                            )
                                rejected = protocolError();
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
                if (!mounted || active !== generation) return;
                if (rejected) throw rejected;
                received = true;
                const page = parsePageV2(response);
                let effectiveState = state;
                let completedReason = pending?.promise === promise ? pending.reason : reason;
                if (props.url !== false && browser.changed(page.browserState)) {
                    await browser.reset();
                    if (!mounted || active !== generation) return;
                    browser.dispose();
                    browser = makeBrowser();
                    clearLive();
                    const serverDiscardedState =
                        expectedVersion !== null &&
                        page.browserState !== undefined &&
                        expectedVersion !== page.browserState.version;
                    if (state && !serverDiscardedState) {
                        followRead(null, 'reset');
                        return;
                    }
                    effectiveState = null;
                    completedReason = 'reset';
                }
                if (props.paging !== 'source' && page.paging.mode !== 'offset')
                    throw new Error('Source paging requires opt-in');
                if (effectiveState && effectiveState.paging.mode !== page.requested.paging.mode)
                    throw new Error('Mismatched requested mode');
                if (
                    page.requested.paging.mode === 'cursor' &&
                    page.requested.paging.cursor !==
                        (effectiveState?.paging.mode === 'cursor'
                            ? effectiveState.paging.cursor
                            : null)
                )
                    throw new Error('Mismatched incoming cursor');
                if (
                    effectiveState?.paging.mode === 'simple' &&
                    (page.requested.paging.mode !== 'simple' ||
                        page.requested.paging.current !== effectiveState.paging.current)
                )
                    throw new Error('Mismatched simple ordinal');
                const decoded = decode(page, props);
                browser.configure(
                    page.browserState,
                    decoded.definition,
                    effectiveState ? null : page.requested,
                );
                if (!effectiveState && completedReason === 'initial') {
                    const candidate = browser.candidate();
                    if (candidate) {
                        followRead(candidate, 'initial');
                        return;
                    }
                }
                if (
                    effectiveState &&
                    (reason === 'initial' || reason === 'history') &&
                    !browser.candidate()
                ) {
                    followRead(null, 'reset');
                    return;
                }
                const result = acceptWindow(
                    session.value.accepted,
                    decoded,
                    completedReason === 'append',
                    infiniteEnabled,
                );
                if (!mounted || active !== generation) return;
                work = { kind: 'publishing', generation: active };
                session.value = {
                    accepted: result,
                    definitionContext: {
                        definition: result.definition,
                        pages: result.page.paging.pages,
                    },
                    requested: result.page.requested,
                    status: 'success',
                    error: null,
                };
                if (!mounted || active !== generation) {
                    resolve({ status: 'superseded' });
                    return;
                }
                const completedExplicit =
                    pending?.promise === promise ? pending.explicit : explicit;
                listen(
                    page.metadata.channel,
                    completedExplicit,
                    ['reload', 'reset', 'initial'].includes(completedReason),
                );
                if (!mounted || active !== generation) {
                    resolve({ status: 'superseded' });
                    return;
                }
                if (completedExplicit) pollingPaused = false;
                const publication = browser.publish(
                    completedReason === 'reset' ? null : (result.window?.start ?? page.requested),
                    completedReason,
                    () => mounted && active === generation,
                );
                if (reason === 'append' || settledOutcome) {
                    const settled = await settlePublication(publication, active, epoch);
                    if (!mounted || active !== generation) {
                        resolve({ status: 'superseded' });
                        return;
                    }
                    appendOperation = null;
                    if (settled) {
                        infiniteState.value = nextPosition(result) ? 'idle' : 'exhausted';
                        observedReady.value = true;
                        resolve({ status: 'success' });
                    } else resolve({ status: 'failure', error: protocolError() });
                } else {
                    resolve({ status: 'success' });
                    void settlePublication(publication, active, epoch).then((settled) => {
                        if (settled && mounted && active === generation && infiniteEnabled) {
                            infiniteState.value = nextPosition(session.value.accepted ?? result)
                                ? 'idle'
                                : 'exhausted';
                            observedReady.value = true;
                        }
                    });
                }
            } catch (cause) {
                if (!mounted || active !== generation) return;
                const failure =
                    rejected ??
                    (cause instanceof BrowserKeyConflict
                        ? ({
                              kind: 'validation',
                              code: 'duplicate_state_key',
                              message: cause.message,
                          } as const)
                        : !received && cause instanceof HttpNetworkError
                          ? ({
                                kind: 'network',
                                code: 'request_failed',
                                message: 'The data source could not be reached.',
                            } satisfies AdapterError)
                          : protocolError());
                if (
                    state &&
                    (reason === 'initial' || reason === 'history') &&
                    (failure.kind === 'cursor_expired' || failure.code === 'invalid_cursor')
                ) {
                    followRead(null, 'reset');
                    return;
                }
                discardBuffer();
                pollingPaused = true;
                cancelPoll();
                work = { kind: 'idle' };
                const clear =
                    failure.kind === 'access' ||
                    failure.kind === 'protocol' ||
                    failure.code === 'invalid_cursor' ||
                    (failure.kind === 'cursor_expired' &&
                        (!session.value.accepted ||
                            !sameContext(session.value.accepted.page.requested, state)));
                session.value = {
                    ...session.value,
                    accepted: clear ? null : session.value.accepted,
                    definitionContext: clear ? null : session.value.definitionContext,
                    error: Object.freeze(failure),
                    status: 'error',
                };
                if (infiniteEnabled) {
                    restartInfinite =
                        failure.kind === 'cursor_expired' || failure.code === 'invalid_cursor';
                    infiniteState.value = 'paused';
                    appendOperation = null;
                }
                if (!session.value.accepted) clearLive(infiniteEnabled ? 'window' : 'context');
                resolve({ status: 'failure', error: failure });
            } finally {
                if (active === generation) {
                    pending = null;
                    if (work.kind === 'idle') schedulePoll();
                }
            }
        }
        function dispatch() {
            void Promise.resolve().then(run);
        }
        pending = {
            key,
            explicit,
            reason,
            promise,
            settle: resolve,
            cancel: () => form?.cancel(),
            timer: null,
            flush() {
                if (this.timer !== null) {
                    clearTimeout(this.timer);
                    this.timer = null;
                    dispatch();
                }
            },
        };
        if (delay > 0)
            pending.timer = setTimeout(() => {
                if (pending?.promise === promise) pending.timer = null;
                dispatch();
            }, delay);
        else dispatch();
        return promise;
    }
    function apply(command: StateCommand, captured = epoch): Promise<AsyncOutcome> {
        if (!mounted || captured !== epoch) return superseded();
        const result = session.value.accepted,
            current = session.value.requested;
        if (infiniteEnabled && 'options' in command && command.options?.page === 'preserve')
            return invalid();
        const definition =
            result?.definition ??
            (infiniteEnabled ? (session.value.definitionContext?.definition ?? null) : null);
        if (!definition || !current) return invalid();
        if (
            (command.kind === 'globalSearch' && !definition.global.search) ||
            (command.kind === 'globalFilter' && !definition.global.filters?.[command.id]) ||
            (command.kind === 'preset' && !definition.global.order) ||
            (command.kind === 'grouping' && definition.global.searchMatch.allowed.length < 2) ||
            (command.kind === 'search' && !definition.fields[command.field]?.search) ||
            (command.kind === 'order' && !definition.fields[command.field]?.order) ||
            (command.kind === 'filter' && !definition.fields[command.field]?.filters?.[command.id])
        )
            return invalid();
        try {
            const next = reduceRequested(
                current,
                command,
                definition,
                result?.page.paging.pages ?? session.value.definitionContext?.pages ?? null,
            );
            if (next === current || stateKey(next) === stateKey(current)) {
                if (
                    infiniteEnabled &&
                    (command.kind === 'page' || command.kind === 'cursor') &&
                    (result?.window?.consumed.length ?? 0) > 1
                )
                    return submit(next, true, 0, 'page');
                if (pending) {
                    if (command.kind !== 'search' && command.kind !== 'globalSearch')
                        pending.flush();
                    return pending.promise;
                }
                return success();
            }
            return submit(
                next,
                false,
                (command.kind === 'search' || command.kind === 'globalSearch') && !command.clear
                    ? (props.searchDebounce ?? 0)
                    : 0,
                command.kind === 'globalSearch'
                    ? 'search'
                    : ['filter', 'order', 'globalFilter', 'preset', 'grouping'].includes(
                            command.kind,
                        )
                      ? 'criteria'
                      : command.kind === 'replace' ||
                          command.kind === 'page' ||
                          command.kind === 'size' ||
                          command.kind === 'search'
                        ? command.kind
                        : 'criteria',
            );
        } catch {
            return invalid();
        }
    }
    function sameContext(left: RequestedStateV2, right: RequestedStateV2 | null): boolean {
        if (!right) return false;
        return (
            stateKey({
                ...left,
                paging: { mode: left.paging.mode, per_page: left.paging.per_page },
            }) ===
            stateKey({
                ...right,
                paging: { mode: right.paging.mode, per_page: right.paging.per_page },
            })
        );
    }
    function first(): Promise<AsyncOutcome> {
        const current = session.value.requested;
        if (!current) return props.paging === 'source' ? invalid() : success();
        if (current.paging.mode === 'cursor')
            return submit(
                freeze({ ...current, paging: { ...current.paging, cursor: null } }),
                false,
                0,
                'page',
            );
        if (!session.value.accepted)
            return submit(
                freeze({ ...current, paging: { ...current.paging, current: 1 } }),
                false,
                0,
                'page',
            );
        return goToPage(1);
    }
    function move(forward: boolean): Promise<AsyncOutcome> {
        const result = session.value.accepted,
            current = session.value.requested;
        if (!result || !current) return props.paging === 'source' ? invalid() : success();
        if (current.paging.mode === 'cursor') {
            if (
                result.page.paging.mode !== 'cursor' ||
                !sameContext(result.page.requested, current)
            )
                return invalid();
            const token = forward
                ? result.page.paging.next_cursor
                : result.page.paging.previous_cursor;
            if (token === null) return success();
            return apply({ kind: 'cursor', value: token });
        }
        const position = current.paging.current;
        if (forward && result.page.paging.mode === 'offset' && position >= result.page.paging.pages)
            return success();
        if (forward ? result.page.paging.has_next : position > 1)
            return goToPage(position + (forward ? 1 : -1));
        return success();
    }
    const reload = () =>
            submit(
                infiniteEnabled ? firstPosition(session.value.requested) : session.value.requested,
                true,
                0,
                'reload',
            ),
        resetState = () => submit(null, true, 0, 'reset'),
        replaceState = (value: unknown) => apply({ kind: 'replace', value });
    const goToPage = (value: number) => apply({ kind: 'page', value }),
        setValue = (value: number) => apply({ kind: 'size', value });
    function append(retry = false): Promise<AsyncOutcome> {
        if (!mounted) return superseded();
        if (appendOperation) return appendOperation.promise;
        if (pending || work.kind !== 'idle') return superseded();
        if (infiniteState.value === 'paused') {
            if (!retry) return superseded();
            if (restartInfinite || !session.value.accepted)
                return submit(
                    firstPosition(session.value.requested),
                    true,
                    0,
                    'reload',
                    true,
                    true,
                );
        } else if (retry) return success();
        const result = session.value.accepted;
        if (!result) return superseded();
        const next = nextPosition(result);
        if (!next) {
            infiniteState.value = 'exhausted';
            return success();
        }
        return submit(next, true, 0, 'append');
    }
    const stopObservation =
        props.infinite && typeof props.infinite === 'object'
            ? observeInfinite(props.infinite, {
                  ready: () => observedReady.value,
                  status: () => infiniteState.value,
                  append: () => append(),
              })
            : () => {};
    const state = computed<AnyDataAdapterHandle>(() => {
        const result = session.value.accepted;
        const definition =
            result?.definition ??
            (infiniteEnabled ? (session.value.definitionContext?.definition ?? null) : null);
        const captured = epoch;
        const own = (action: () => Promise<AsyncOutcome>) => () =>
            mounted && captured === epoch ? action() : superseded();
        const controls = {
            loading: session.value.status === 'loading',
            empty: !!result && !result.page.data.length,
            has_previous: result?.page.paging.has_previous ?? false,
            has_next: result?.page.paging.has_next ?? false,
            per_page: Object.freeze({
                value: result?.page.paging.per_page ?? null,
                options: result?.definition.paging.per_page_options ?? Object.freeze([]),
                setValue: (value: number) =>
                    mounted && captured === epoch ? setValue(value) : superseded(),
            }),
            goToFirst: own(first),
            goToPrevious: own(() => move(false)),
            goToNext: own(() => move(true)),
            reload: own(reload),
        };
        const page = result?.page.paging;
        let paging: SourcePaging;
        if (!page && props.paging === 'source') {
            paging = Object.freeze({
                ...controls,
                mode: null,
                empty: false,
                has_previous: false,
                has_next: false,
                per_page: Object.freeze({
                    value: null,
                    options: Object.freeze<[]>([]),
                    setValue: controls.per_page.setValue,
                }),
                current: null,
                pages: null,
                counts: null,
                total: null,
                filtered: null,
                previous_cursor: null,
                next_cursor: null,
                goToPage: null,
                goToLast: null,
            });
        } else if (!page || page.mode === 'offset') {
            paging = Object.freeze({
                ...controls,
                mode: 'offset',
                current: page?.current ?? null,
                pages: page?.pages ?? null,
                total: page?.total ?? null,
                filtered: page?.filtered ?? null,
                goToPage: (value: number) =>
                    mounted && captured === epoch ? goToPage(value) : superseded(),
                goToLast: own(() => {
                    const accepted = session.value.accepted?.page.paging;
                    return accepted?.mode === 'offset' ? goToPage(accepted.pages) : success();
                }),
            } satisfies OffsetPaging);
        } else {
            const count =
                page.total === null || page.filtered === null
                    ? ({ counts: 'unknown', total: null, filtered: null } as const)
                    : ({ counts: 'exact', total: page.total, filtered: page.filtered } as const);
            const shared = {
                ...controls,
                per_page: Object.freeze({ ...controls.per_page, value: page.per_page }),
                ...count,
                pages: null,
                goToPage: null,
                goToLast: null,
            } as const;
            paging =
                page.mode === 'simple'
                    ? Object.freeze({
                          ...shared,
                          mode: 'simple',
                          current: page.current,
                          previous_cursor: null,
                          next_cursor: null,
                      })
                    : Object.freeze({
                          ...shared,
                          mode: 'cursor',
                          current: null,
                          previous_cursor: page.previous_cursor,
                          next_cursor: page.next_cursor,
                      });
        }
        const common = {
            status: session.value.status,
            error: session.value.error,
            integrationError: integrationError.value,
            infinite: infiniteEnabled
                ? Object.freeze({
                      status: infiniteState.value,
                      append: own(() => append()),
                      retry: own(() => append(true)),
                  })
                : null,
            resetContext: own(resetContext),
            share: () => {
                const accepted = session.value.accepted;
                return mounted && captured === epoch && accepted
                    ? browser.share(accepted.window?.start ?? accepted.page.requested)
                    : null;
            },
            restartPolling: () => {
                if (mounted && captured === epoch) restartPolling();
            },
            requested: session.value.requested,
            accepted: result?.page.state ?? null,
            paging,
            definition: definition,
            capabilities: definition,
            metadata: result?.page.metadata ?? null,
            fields: definition
                ? project(definition, session.value.requested, (command) =>
                      apply(command, captured),
                  )
                : null,
            global: definition
                ? projectGlobal(definition, session.value.requested, (command) =>
                      apply(command, captured),
                  )
                : null,
            setSearchMatch:
                definition && definition.global.searchMatch.allowed.length > 1
                    ? (
                          value: 'all' | 'any',
                          options?: import('./requested-state.js').StateOptions,
                      ) =>
                          apply(
                              { kind: 'grouping', value, ...(options ? { options } : {}) },
                              captured,
                          )
                    : null,
            actions: actions.controls(),
            replaceState: (value: unknown) =>
                mounted && captured === epoch ? replaceState(value) : superseded(),
            resetState: own(resetState),
        };
        return result
            ? Object.freeze({ ...common, ready: true, data: result.data })
            : Object.freeze({ ...common, ready: false, data: Object.freeze<[]>([]) });
    });
    function resetContext(): Promise<AsyncOutcome> {
        epoch++;
        actions.reset();
        invalidate();
        clearLive();
        session.value = {
            accepted: null,
            definitionContext: null,
            requested: null,
            error: null,
            status: 'idle',
        };
        pollingPaused = false;
        const clearing = browser.reset();
        return mounted
            ? submit(null, true, 0, 'reset')
            : clearing.then(() => ({ status: 'superseded' }));
    }
    watch(
        () => [props.source, props.decodeRow, props.decodeDefinition, props.actionDefinitions],
        () => {
            epoch++;
            actions.reset();
            clearLive();
            browser.dispose();
            invalidate();
            session.value = {
                accepted: null,
                definitionContext: null,
                requested: null,
                error: null,
                status: 'idle',
            };
            browser = makeBrowser();
            if (mounted) initial();
        },
        { flush: 'sync' },
    );
    return {
        state,
        start() {
            if (mounted) return;
            mounted = true;
            if (polling) {
                if (typeof document !== 'undefined')
                    document.addEventListener('visibilitychange', availability);
                if (typeof window !== 'undefined') {
                    window.addEventListener('online', availability);
                    window.addEventListener('offline', availability);
                }
            }
            initial();
        },
        dispose() {
            stopObservation();
            mounted = false;
            if (polling) {
                if (typeof document !== 'undefined')
                    document.removeEventListener('visibilitychange', availability);
                if (typeof window !== 'undefined') {
                    window.removeEventListener('online', availability);
                    window.removeEventListener('offline', availability);
                }
            }
            epoch++;
            actions.reset();
            clearLive();
            browser.dispose();
            invalidate();
            session.value = {
                accepted: null,
                definitionContext: null,
                requested: null,
                error: null,
                status: 'idle',
            };
        },
    };
}
