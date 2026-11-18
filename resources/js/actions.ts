import { router } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { shallowRef } from 'vue';
import { dictionary, freeze, object } from './protocol.js';
import type {
    Errors,
    FormDataConvertible,
    RequestPayload,
    VisitOptions,
    UseHttpSubmitOptions as NativeHttpOptions,
} from '#dapter-native-action-types';

type UseHttpSubmitOptions = NativeHttpOptions<unknown>;

export type ActionTransport = 'visit' | 'http';
export type ActionDefinition = {
    readonly transports: readonly ActionTransport[];
    readonly decodeInput?: (value: unknown) => unknown;
    readonly decodeResult?: (value: unknown) => unknown;
};
export type ActionDefinitions = Readonly<Record<string, ActionDefinition>>;
export function defineActions<const A extends ActionDefinitions>(definitions: A): A {
    return definitions;
}
export type ActionMetadata = {
    readonly method: 'post' | 'put' | 'patch' | 'delete';
    readonly transports: readonly ActionTransport[];
} & (
    | { readonly url: string; readonly target: null }
    | {
          readonly target: {
              readonly input: string;
              readonly valueType: 'string' | 'integer';
              readonly values: readonly { readonly value: string | number; readonly url: string }[];
          };
      }
);
export type ActionMetadataMap = Readonly<Record<string, ActionMetadata | undefined>>;
export type ActionVisitOptions = Omit<VisitOptions, 'url' | 'method' | 'data' | 'headers'>;
export type ActionHttpOptions = Omit<UseHttpSubmitOptions, 'headers' | 'optimistic'>;
export type WriteOutcome<Result = unknown> =
    | { readonly status: 'completed'; readonly commit: 'confirmed'; readonly data: Result }
    | { readonly status: 'rejected'; readonly commit: 'not_started'; readonly error: unknown }
    | { readonly status: 'validation'; readonly commit: 'unknown'; readonly errors: Errors }
    | { readonly status: 'cancelled'; readonly commit: 'unknown' }
    | { readonly status: 'failed'; readonly commit: 'unknown'; readonly error: unknown };
type Ids<A extends ActionDefinitions, T extends ActionTransport> = {
    [K in keyof A]: T extends A[K]['transports'][number] ? K : never;
}[keyof A] &
    string;
type Input<A> = A extends { readonly decodeInput: (value: unknown) => infer I } ? I : unknown;
type Output<A> = A extends { readonly decodeResult: (value: unknown) => infer O } ? O : unknown;
export type ActionControls<A extends ActionDefinitions = ActionDefinitions> = {
    readonly available: ActionMetadataMap;
    readonly pending: number;
    readonly error: unknown;
    readonly validation: Errors | null;
    visit<K extends Ids<A, 'visit'>>(id: K, input: Input<A[K]>, options?: ActionVisitOptions): void;
    http<K extends Ids<A, 'http'>>(
        id: K,
        input: Input<A[K]>,
        options?: ActionHttpOptions,
    ): Promise<WriteOutcome<Output<A[K]>>>;
};
function exact(input: Record<string, unknown>, required: string[], optional: string[] = []): void {
    if (
        required.some((key) => !Object.hasOwn(input, key)) ||
        Object.keys(input).some((key) => !required.includes(key) && !optional.includes(key))
    )
        throw new Error('Invalid action metadata');
}
function identifier(value: unknown): string {
    if (typeof value !== 'string' || !/^[A-Za-z_][A-Za-z_0-9]*$/.test(value))
        throw new Error('Invalid action identifier');
    return value;
}
function endpoint(value: unknown): string {
    if (
        typeof value !== 'string' ||
        !value ||
        Array.from(value).some((character) => character.charCodeAt(0) <= 32) ||
        value.startsWith('//')
    )
        throw new Error('Invalid action URL');
    const url = new URL(value, 'http://dapter.invalid');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password)
        throw new Error('Invalid action URL');
    return value;
}
export function parseActions(value: unknown): ActionMetadataMap {
    const result = dictionary<ActionMetadata>();
    for (const [id, raw] of Object.entries(object(value))) {
        identifier(id);
        const input = object(raw),
            method = input.method;
        if (method !== 'post' && method !== 'put' && method !== 'patch' && method !== 'delete')
            throw new Error('Invalid action method');
        if (!Array.isArray(input.transports) || !input.transports.length)
            throw new Error('Invalid action transports');
        const transports = input.transports.map((mode: unknown): ActionTransport => {
            if (mode === 'visit' || mode === 'http') return mode;
            throw new Error('Invalid action transport');
        });
        if (new Set(transports).size !== transports.length)
            throw new Error('Duplicate action transport');
        if (input.target === null) {
            exact(input, ['method', 'transports', 'target', 'url']);
            result[id] = { method, transports, target: null, url: endpoint(input.url) };
        } else {
            exact(input, ['method', 'transports', 'target']);
            const target = object(input.target);
            exact(target, ['input', 'valueType', 'values']);
            const selector = identifier(target.input),
                valueType = target.valueType;
            if (
                (valueType !== 'string' && valueType !== 'integer') ||
                !Array.isArray(target.values) ||
                !target.values.length
            )
                throw new Error('Invalid action target');
            const values = target.values.map((raw: unknown) => {
                const item = object(raw);
                exact(item, ['value', 'url']);
                const value = item.value;
                if (
                    valueType === 'string'
                        ? typeof value !== 'string'
                        : typeof value !== 'number' || !Number.isSafeInteger(value)
                )
                    throw new Error('Invalid action target value');
                if (typeof value !== 'string' && typeof value !== 'number')
                    throw new Error('Invalid action target value');
                return { value, url: endpoint(item.url) };
            });
            if (new Set(values.map((item) => item.value)).size !== values.length)
                throw new Error('Duplicate action target');
            result[id] = { method, transports, target: { input: selector, valueType, values } };
        }
    }
    if (!Object.keys(result).length) throw new Error('Empty action metadata');
    return freeze(result);
}
function convertible(value: unknown, ancestors: Set<object>): value is FormDataConvertible {
    if (
        value === null ||
        value === undefined ||
        typeof value === 'string' ||
        typeof value === 'boolean' ||
        typeof value === 'bigint'
    )
        return true;
    if (typeof value === 'number') return Number.isFinite(value);
    if (value instanceof Date) return !Number.isNaN(value.valueOf());
    if (typeof Blob !== 'undefined' && value instanceof Blob) return true;
    if (typeof value !== 'object' || ancestors.has(value)) return false;
    ancestors.add(value);
    let accepted = false;
    try {
        accepted = (Array.isArray(value) ? value : Object.values(object(value))).every((item) =>
            convertible(item, ancestors),
        );
    } catch {
        accepted = false;
    }
    ancestors.delete(value);
    return accepted;
}
function payload(value: unknown): Record<string, FormDataConvertible> {
    if (typeof FormData !== 'undefined' && value instanceof FormData)
        throw new Error('Use a native payload record instead of FormData');
    const input = object(value),
        result = dictionary<FormDataConvertible>();
    for (const [key, item] of Object.entries(input)) {
        if (!convertible(item, new Set())) throw new Error('Invalid action input');
        result[key] = item;
    }
    return result;
}
function safeOptions(value: unknown, mode: ActionTransport): void {
    if (value === undefined) return;
    const input = object(value);
    const forbidden = [
        'url',
        'method',
        'data',
        'headers',
        'source',
        'transport',
        'target',
        ...(mode === 'http'
            ? [
                  'optimistic',
                  'preserveScroll',
                  'preserveState',
                  'replace',
                  'only',
                  'except',
                  'reset',
              ]
            : []),
    ];
    if (forbidden.some((key) => Object.hasOwn(input, key)))
        throw new Error('Reserved action option');
}
function nativeInputKey(
    value: unknown,
    blobs: Map<Blob, number>,
    ancestors = new Set<object>(),
): string {
    if (value === null || typeof value !== 'object') return `${typeof value}:${String(value)}`;
    if (typeof Blob !== 'undefined' && value instanceof Blob) {
        if (!blobs.has(value)) blobs.set(value, blobs.size);
        return `blob:${blobs.get(value)}`;
    }
    if (value instanceof Date) return `date:${value.valueOf()}`;
    if (ancestors.has(value)) throw new Error('Invalid action input');
    ancestors.add(value);
    try {
        if (typeof FormData !== 'undefined' && value instanceof FormData)
            return `form:${JSON.stringify(Array.from(value.entries(), ([key, item]) => [key, nativeInputKey(item, blobs, ancestors)]))}`;
        if (Array.isArray(value))
            return `array:${JSON.stringify(value.map((item) => nativeInputKey(item, blobs, ancestors)))}`;
        return `record:${JSON.stringify(Object.entries(object(value)).map(([key, item]) => [key, nativeInputKey(item, blobs, ancestors)]))}`;
    } finally {
        ancestors.delete(value);
    }
}
export function createActionController<A extends ActionDefinitions>(options: {
    metadata: () => ActionMetadataMap | null;
    definitions: () => A | undefined;
    active: () => boolean;
    epoch: () => number;
}) {
    const state = shallowRef<{ pending: number; error: unknown; validation: Errors | null }>({
        pending: 0,
        error: null,
        validation: null,
    });
    function allowed(): ActionMetadataMap | null {
        const metadata = options.metadata();
        if (!metadata) return null;
        const definitions = options.definitions();
        if (!definitions) return metadata;
        const result = dictionary<ActionMetadata>();
        for (const [id, action] of Object.entries(metadata)) {
            const definition = Object.hasOwn(definitions, id) ? definitions[id] : undefined;
            if (!action || !definition) continue;
            const transports = action.transports.filter((mode) =>
                definition.transports.includes(mode),
            );
            if (transports.length) result[id] = { ...action, transports };
        }
        return Object.keys(result).length ? freeze(result) : null;
    }
    function prepare(
        id: string,
        input: unknown,
        mode: ActionTransport,
        captured: number,
        native: unknown,
    ) {
        if (!options.active() || options.epoch() !== captured)
            throw new Error('Inactive action owner');
        safeOptions(native, mode);
        const action = allowed()?.[id];
        if (!action || !action.transports.includes(mode)) throw new Error('Unavailable action');
        const definition = options.definitions()?.[id],
            data = payload(definition?.decodeInput ? definition.decodeInput(input) : input);
        const authorized = authorize(id, data, mode, captured);
        return { ...authorized, data, definition };
    }
    function authorize(
        id: string,
        data: Record<string, FormDataConvertible>,
        mode: ActionTransport,
        captured: number,
    ) {
        if (!options.active() || options.epoch() !== captured)
            throw new Error('Inactive action owner');
        const action = allowed()?.[id];
        if (!action || !action.transports.includes(mode)) throw new Error('Unavailable action');
        let url: string;
        if (action.target === null) url = action.url;
        else {
            const value = data[action.target.input];
            const selected = action.target.values.find((item) => item.value === value);
            if (!selected) throw new Error('Unavailable action target');
            url = selected.url;
        }
        if (!options.active() || options.epoch() !== captured)
            throw new Error('Inactive action owner');
        return { action, url };
    }
    function trackWrite(captured: number) {
        const current = () => options.active() && options.epoch() === captured;
        let phase: 'preflight' | 'pending' | 'settled' = 'preflight';
        function start() {
            if (!current() || phase !== 'preflight') return;
            phase = 'pending';
            state.value = { pending: state.value.pending + 1, error: null, validation: null };
        }
        function end(outcome?: WriteOutcome) {
            if (!current()) return;
            if (phase === 'settled') {
                if (outcome?.status === 'failed')
                    state.value = { ...state.value, error: outcome.error, validation: null };
                return;
            }
            const pending = phase === 'pending' ? state.value.pending - 1 : state.value.pending;
            phase = 'settled';
            state.value = {
                pending,
                error:
                    outcome?.status === 'failed' || outcome?.status === 'rejected'
                        ? outcome.error
                        : null,
                validation: outcome?.status === 'validation' ? outcome.errors : null,
            };
        }
        return { start, end };
    }
    function controls(): ActionControls<A> | null;
    function controls(): ActionControls | null {
        const available = allowed();
        if (!available) return null;
        const captured = options.epoch();
        return Object.freeze({
            available,
            ...state.value,
            visit(id: string, input: unknown, native?: ActionVisitOptions): void {
                let prepared: ReturnType<typeof prepare>;
                try {
                    prepared = prepare(id, input, 'visit', captured, native);
                } catch (error) {
                    if (options.active() && options.epoch() === captured)
                        state.value = { ...state.value, error };
                    return;
                }
                const { start, end } = trackWrite(captured);
                function guardVisit(visit: Parameters<NonNullable<VisitOptions['onBefore']>>[0]) {
                    const blobs = new Map<Blob, number>(),
                        owned = {
                            url: visit.url.href,
                            method: visit.method,
                            data: nativeInputKey(visit.data, blobs),
                            headers: nativeInputKey(visit.headers, blobs),
                        };
                    return () => {
                        const current = authorize(id, prepared.data, 'visit', captured);
                        if (
                            current.url !== prepared.url ||
                            current.action.method !== prepared.action.method ||
                            visit.url.href !== owned.url ||
                            visit.method !== owned.method ||
                            nativeInputKey(visit.data, blobs) !== owned.data ||
                            nativeInputKey(visit.headers, blobs) !== owned.headers
                        )
                            throw new Error('Reserved action override');
                    };
                }
                try {
                    router.visit<RequestPayload>(prepared.url, {
                        ...native,
                        method: prepared.action.method,
                        data: prepared.data,
                        headers: Object.freeze({
                            'X-Dapter-Action': '1',
                            'X-Dapter-Transport': 'visit',
                        }),
                        onBefore(visit) {
                            try {
                                const check = guardVisit(visit);
                                const result = native?.onBefore?.(visit);
                                if (result === false)
                                    end({
                                        status: 'rejected',
                                        commit: 'not_started',
                                        error: new Error('Action cancelled before dispatch'),
                                    });
                                if (result !== false) {
                                    try {
                                        check();
                                    } catch (error) {
                                        end({ status: 'rejected', commit: 'not_started', error });
                                        return false;
                                    }
                                }
                                return result;
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                        onStart(visit) {
                            start();
                            const check = guardVisit(visit);
                            try {
                                native?.onStart?.(visit);
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                            try {
                                check();
                            } catch (error) {
                                end({ status: 'rejected', commit: 'not_started', error });
                                throw error;
                            }
                        },
                        onError(errors) {
                            try {
                                const result = native?.onError?.(errors);
                                end({ status: 'validation', commit: 'unknown', errors });
                                return result;
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                        onCancel() {
                            try {
                                native?.onCancel?.();
                                end({ status: 'cancelled', commit: 'unknown' });
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                        onSuccess(page) {
                            try {
                                return native?.onSuccess?.(page);
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                        onHttpException(response) {
                            try {
                                const result = native?.onHttpException?.(response);
                                end({ status: 'failed', commit: 'unknown', error: response });
                                return result;
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                        onNetworkError(error) {
                            try {
                                const result = native?.onNetworkError?.(error);
                                end({ status: 'failed', commit: 'unknown', error });
                                return result;
                            } catch (cause) {
                                end({ status: 'failed', commit: 'unknown', error: cause });
                                throw cause;
                            }
                        },
                        onFinish(visit) {
                            try {
                                native?.onFinish?.(visit);
                                end();
                            } catch (error) {
                                end({ status: 'failed', commit: 'unknown', error });
                                throw error;
                            }
                        },
                    } satisfies VisitOptions);
                } catch (error) {
                    end({ status: 'failed', commit: 'unknown', error });
                }
            },
            async http(
                id: string,
                input: unknown,
                native?: ActionHttpOptions,
            ): Promise<WriteOutcome> {
                let prepared;
                try {
                    prepared = prepare(id, input, 'http', captured, native);
                } catch (error) {
                    const outcome: WriteOutcome = {
                        status: 'rejected',
                        commit: 'not_started',
                        error,
                    };
                    if (options.active() && options.epoch() === captured)
                        state.value = { ...state.value, error };
                    return outcome;
                }
                const { start, end } = trackWrite(captured);
                start();
                let validation: Errors | null = null,
                    cancelled = false,
                    blocked = false,
                    callbackFailed = false;
                function invokeCallback(callback: () => void): void {
                    try {
                        callback();
                    } catch (error) {
                        callbackFailed = true;
                        throw error;
                    }
                }
                try {
                    const form = useHttp<Record<string, never>, unknown>({})
                        .transform(() => prepared.data)
                        .withAllErrors();
                    const response = await form[prepared.action.method](prepared.url, {
                        ...native,
                        headers: Object.freeze({
                            'X-Dapter-Action': '1',
                            'X-Dapter-Transport': 'http',
                        }),
                        onBefore() {
                            const result = native?.onBefore?.();
                            if (result === false) blocked = true;
                            if (!blocked) {
                                try {
                                    const current = authorize(id, prepared.data, 'http', captured);
                                    if (
                                        current.url !== prepared.url ||
                                        current.action.method !== prepared.action.method
                                    )
                                        throw new Error('Unavailable action');
                                } catch {
                                    blocked = true;
                                    return false;
                                }
                            }
                            return result;
                        },
                        onStart() {
                            invokeCallback(() => native?.onStart?.());
                            try {
                                const current = authorize(id, prepared.data, 'http', captured);
                                if (
                                    current.url !== prepared.url ||
                                    current.action.method !== prepared.action.method
                                )
                                    throw new Error('Unavailable action');
                            } catch (error) {
                                blocked = true;
                                throw error;
                            }
                        },
                        onError(errors) {
                            validation = errors;
                            invokeCallback(() => native?.onError?.(errors));
                        },
                        onCancel() {
                            cancelled = true;
                            invokeCallback(() => native?.onCancel?.());
                        },
                        onFinish() {
                            invokeCallback(() => native?.onFinish?.());
                        },
                    } satisfies UseHttpSubmitOptions);
                    const outcome: WriteOutcome = validation
                        ? { status: 'validation', commit: 'unknown', errors: validation }
                        : cancelled
                          ? { status: 'cancelled', commit: 'unknown' }
                          : blocked
                            ? {
                                  status: 'rejected',
                                  commit: 'not_started',
                                  error: new Error('Action cancelled before dispatch'),
                              }
                            : {
                                  status: 'completed',
                                  commit: 'confirmed',
                                  data: prepared.definition?.decodeResult
                                      ? prepared.definition.decodeResult(response)
                                      : response,
                              };
                    end(outcome);
                    return outcome;
                } catch (error) {
                    const outcome: WriteOutcome = callbackFailed
                        ? { status: 'failed', commit: 'unknown', error }
                        : validation
                          ? { status: 'validation', commit: 'unknown', errors: validation }
                          : blocked
                            ? { status: 'rejected', commit: 'not_started', error }
                            : cancelled
                              ? { status: 'cancelled', commit: 'unknown' }
                              : { status: 'failed', commit: 'unknown', error };
                    end(outcome);
                    return outcome;
                }
            },
        });
    }
    return {
        controls,
        reset() {
            state.value = { pending: 0, error: null, validation: null };
        },
    };
}
