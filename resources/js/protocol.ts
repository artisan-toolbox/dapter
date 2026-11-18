import { parseActions, type ActionMetadataMap } from './actions.js';
export type FailureKind = 'access' | 'validation' | 'unsupported_operation' | 'server';
export type AdapterError =
    | {
          readonly kind: 'cursor_expired';
          readonly code: 'cursor_expired';
          readonly message: string;
          readonly recovery: 'restart';
      }
    | {
          readonly kind: FailureKind | 'protocol' | 'network';
          readonly code: string;
          readonly message: string;
      };
export type RequestedState = {
    readonly version: 1;
    readonly fields: Readonly<Record<string, never>>;
    readonly global: Readonly<Record<string, never>>;
    readonly searchMatch: 'all';
    readonly order: { readonly kind: 'default' };
    readonly paging: {
        readonly mode: 'offset';
        readonly current: number;
        readonly per_page: number;
    };
};
export type AcceptedState = {
    readonly order: { readonly kind: 'source' };
    readonly paging: RequestedState['paging'];
};
export type Page = {
    readonly data: readonly unknown[];
    readonly requested: RequestedState;
    readonly state: AcceptedState;
    readonly paging: RequestedState['paging'] & {
        readonly pages: number;
        readonly total: number;
        readonly filtered: number;
        readonly has_previous: boolean;
        readonly has_next: boolean;
    };
    readonly options: readonly number[];
    readonly actions?: ActionMetadataMap;
};
export const protocolError = (): AdapterError => ({
    kind: 'protocol',
    code: 'invalid_response',
    message: 'The data source returned an invalid response.',
});
function isObject(value: unknown): value is Record<string, unknown> {
    return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null)
    );
}
export function dictionary<T>(): Record<string, T> {
    const result: Record<string, T> = {};
    Object.setPrototypeOf(result, null);
    return result;
}
export function object(value: unknown): Record<string, unknown> {
    if (!isObject(value)) throw new Error('Invalid object');
    return value;
}
function keys(value: Record<string, unknown>, names: readonly string[]): void {
    if (
        Object.keys(value).length !== names.length ||
        names.some((name) => !Object.hasOwn(value, name))
    )
        throw new Error('Invalid properties');
}
function integer(value: unknown, minimum = 1): number {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < minimum)
        throw new Error('Invalid integer');
    return value;
}
function fixed(value: unknown, expected: unknown): void {
    if (value !== expected) throw new Error('Invalid value');
}
export function intent(current: number, perPage: number): RequestedState {
    return {
        version: 1,
        fields: {},
        global: {},
        searchMatch: 'all',
        order: { kind: 'default' },
        paging: { mode: 'offset', current, per_page: perPage },
    };
}
function parseIntent(value: unknown): RequestedState {
    const state = object(value);
    keys(state, ['version', 'fields', 'global', 'searchMatch', 'order', 'paging']);
    fixed(state.version, 1);
    keys(object(state.fields), []);
    keys(object(state.global), []);
    fixed(state.searchMatch, 'all');
    const order = object(state.order);
    keys(order, ['kind']);
    fixed(order.kind, 'default');
    const paging = object(state.paging);
    keys(paging, ['mode', 'current', 'per_page']);
    fixed(paging.mode, 'offset');
    return intent(integer(paging.current), integer(paging.per_page));
}
export function validateJson(value: unknown): void {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') return;
    if (
        typeof value === 'number' &&
        Number.isFinite(value) &&
        (!Number.isInteger(value) || Number.isSafeInteger(value))
    )
        return;
    if (Array.isArray(value)) {
        for (const item of value) validateJson(item);
        return;
    }
    for (const item of Object.values(object(value))) validateJson(item);
}
export type ReadonlyDeep<T> = T extends (...args: never[]) => unknown
    ? T
    : T extends object
      ? { readonly [K in keyof T]: ReadonlyDeep<T[K]> }
      : T;
export function freeze<T>(value: T): ReadonlyDeep<T>;
export function freeze(value: unknown): unknown {
    return freezeStructure(value);
}
function freezeStructure(value: unknown): unknown {
    if (Array.isArray(value))
        return Object.freeze(value.map((item: unknown) => freezeStructure(item)));
    if (isObject(value)) {
        const result = Object.fromEntries(
            Object.entries(value).map(([key, item]) => [key, freezeStructure(item)]),
        );
        if (Object.getPrototypeOf(value) === null) Object.setPrototypeOf(result, null);
        return Object.freeze(result);
    }
    return value;
}
export function parsePage(value: unknown): Page {
    const page = object(value);
    keys(page, [
        'version',
        'data',
        'requested',
        'state',
        'paging',
        'capabilities',
        ...(Object.hasOwn(page, 'actions') ? ['actions'] : []),
    ]);
    fixed(page.version, 1);
    if (!Array.isArray(page.data)) throw new Error('Invalid rows');
    validateJson(page.data);
    const requested = parseIntent(page.requested);
    const state = object(page.state);
    keys(state, ['order', 'paging']);
    const order = object(state.order);
    keys(order, ['kind']);
    fixed(order.kind, 'source');
    const accepted = object(state.paging);
    keys(accepted, ['mode', 'current', 'per_page']);
    fixed(accepted.mode, 'offset');
    const paging = object(page.paging);
    keys(paging, [
        'mode',
        'current',
        'pages',
        'per_page',
        'total',
        'filtered',
        'has_previous',
        'has_next',
    ]);
    fixed(paging.mode, 'offset');
    const current = integer(paging.current),
        perPage = integer(paging.per_page),
        pages = integer(paging.pages),
        total = integer(paging.total, 0),
        filtered = integer(paging.filtered, 0);
    fixed(accepted.current, current);
    fixed(accepted.per_page, perPage);
    fixed(requested.paging.current, current);
    fixed(requested.paging.per_page, perPage);
    fixed(pages, Math.max(1, Math.ceil(filtered / perPage)));
    if (current > pages || filtered > total) throw new Error('Invalid counts');
    fixed(paging.has_previous, current > 1);
    fixed(paging.has_next, current < pages);
    fixed(page.data.length, Math.min(perPage, Math.max(0, filtered - (current - 1) * perPage)));
    const capabilities = object(page.capabilities);
    keys(capabilities, ['fields', 'global', 'paging']);
    keys(object(capabilities.fields), []);
    const global = object(capabilities.global);
    keys(global, ['multi_order', 'multi_order_limit', 'searchMatch', 'filters', 'search', 'order']);
    fixed(global.multi_order, false);
    fixed(global.multi_order_limit, 1);
    fixed(global.filters, null);
    fixed(global.search, null);
    fixed(global.order, null);
    const search = object(global.searchMatch);
    keys(search, ['default', 'allowed']);
    fixed(search.default, 'all');
    if (
        !Array.isArray(search.allowed) ||
        search.allowed.length !== 1 ||
        search.allowed[0] !== 'all'
    )
        throw new Error('Invalid match');
    const sizes = object(capabilities.paging);
    keys(sizes, ['mode', 'per_page', 'per_page_options']);
    fixed(sizes.mode, 'offset');
    const defaultPerPage = integer(sizes.per_page);
    if (!Array.isArray(sizes.per_page_options) || sizes.per_page_options.length === 0)
        throw new Error('Invalid sizes');
    const options = sizes.per_page_options.map((item: unknown) => integer(item));
    if (
        new Set(options).size !== options.length ||
        !options.includes(perPage) ||
        !options.includes(defaultPerPage)
    )
        throw new Error('Invalid sizes');
    return freeze({
        data: page.data,
        ...(Object.hasOwn(page, 'actions') ? { actions: parseActions(page.actions) } : {}),
        requested,
        state: {
            order: { kind: 'source' },
            paging: { mode: 'offset', current, per_page: perPage },
        },
        paging: {
            mode: 'offset',
            current,
            pages,
            per_page: perPage,
            total,
            filtered,
            has_previous: current > 1,
            has_next: current < pages,
        },
        options,
    });
}
export function failureKind(value: unknown): FailureKind | null {
    return value === 'access' ||
        value === 'validation' ||
        value === 'unsupported_operation' ||
        value === 'server'
        ? value
        : null;
}
export function parseFailure(value: unknown, version: 1 | 2 = 1): AdapterError | null {
    try {
        const envelope = object(value);
        keys(
            envelope,
            Object.hasOwn(envelope, 'errors')
                ? ['version', 'error', 'errors']
                : ['version', 'error'],
        );
        fixed(envelope.version, version);
        const error = object(envelope.error);
        if (version === 2 && error.kind === 'cursor_expired') {
            keys(envelope, ['version', 'error']);
            keys(error, ['kind', 'code', 'message', 'recovery']);
            if (
                error.code !== 'cursor_expired' ||
                error.recovery !== 'restart' ||
                error.message !== 'The cursor has expired. Restart pagination to continue.'
            )
                return null;
            return {
                kind: 'cursor_expired',
                code: 'cursor_expired',
                message: error.message,
                recovery: 'restart',
            };
        }
        keys(error, ['kind', 'code', 'message']);
        const kind = failureKind(error.kind);
        if (
            !kind ||
            typeof error.code !== 'string' ||
            !error.code ||
            typeof error.message !== 'string' ||
            !error.message
        )
            return null;
        if (Object.hasOwn(envelope, 'errors')) {
            const errors = object(envelope.errors);
            keys(
                errors,
                version === 2 && error.code === 'invalid_cursor'
                    ? ['_dapter_kind', '_dapter_code']
                    : ['_dapter_kind'],
            );
            if (
                version === 2 &&
                error.code === 'invalid_cursor' &&
                (!Array.isArray(errors._dapter_code) ||
                    errors._dapter_code.length !== 1 ||
                    errors._dapter_code[0] !== 'invalid_cursor')
            )
                return null;
            if (!Array.isArray(errors._dapter_kind) || errors._dapter_kind.length !== 1)
                return null;
            fixed(errors._dapter_kind[0], kind);
        }
        return { kind, code: error.code, message: error.message };
    } catch {
        return null;
    }
}
export function validationFailure(errors: unknown, version: 1 | 2 = 1): AdapterError {
    try {
        const input = object(errors);
        const marker = input._dapter_kind;
        const code = input._dapter_code;
        const kind = Array.isArray(marker) && marker.length === 1 ? failureKind(marker[0]) : null;
        if (kind)
            return {
                kind,
                code:
                    version === 2 &&
                    kind === 'validation' &&
                    Array.isArray(code) &&
                    code.length === 1 &&
                    code[0] === 'invalid_cursor'
                        ? 'invalid_cursor'
                        : 'request_rejected',
                message:
                    kind === 'access'
                        ? 'Access to this data source was denied.'
                        : 'The data source rejected the request.',
            };
    } catch {
        return protocolError();
    }
    return protocolError();
}
