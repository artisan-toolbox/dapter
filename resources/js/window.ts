import type { ActionMetadata, ActionMetadataMap } from './actions.js';
import type { AdapterDefinition, PageV2, SourceRequestedStateV2 } from './protocol-v2.js';
import { dictionary, freeze } from './protocol.js';
import { newer, sameChannel, type RowRecords, type RowUpdate } from './records.js';
import { stateKey } from './requested-state.js';

export type LoadedWindow = {
    readonly start: SourceRequestedStateV2;
    readonly consumed: readonly string[];
    readonly stopped: boolean;
};
export type WindowResult = {
    readonly definition: AdapterDefinition;
    readonly page: PageV2;
    readonly data: readonly unknown[];
    readonly window: LoadedWindow | null;
};
export function boundary(state: SourceRequestedStateV2): string {
    return stateKey(state.paging);
}
export function firstPosition(state: SourceRequestedStateV2 | null): SourceRequestedStateV2 | null {
    if (!state) return null;
    return freeze({
        ...state,
        paging:
            state.paging.mode === 'cursor'
                ? { ...state.paging, cursor: null }
                : { ...state.paging, current: 1 },
    });
}
export function nextPosition(result: WindowResult): SourceRequestedStateV2 | null {
    const { page, window } = result;
    if (!page.paging.has_next || window?.stopped) return null;
    const paging =
        page.paging.mode === 'cursor'
            ? {
                  mode: page.paging.mode,
                  cursor: page.paging.next_cursor,
                  per_page: page.paging.per_page,
              }
            : {
                  mode: page.paging.mode,
                  current: page.paging.current + 1,
                  per_page: page.paging.per_page,
              };
    const next = freeze({ ...page.requested, paging });
    return window?.consumed.includes(boundary(next)) ? null : next;
}
function combineActions(
    left: ActionMetadataMap | undefined,
    right: ActionMetadataMap | undefined,
): ActionMetadataMap | undefined {
    if (!left && !right) return undefined;
    const actions = dictionary<ActionMetadata>();
    const ids = new Set([...Object.keys(left ?? {}), ...Object.keys(right ?? {})]);
    for (const id of ids) {
        const old = left?.[id],
            next = right?.[id];
        if (!old || !next) {
            const exclusive = old ?? next;
            if (!exclusive || exclusive.target === null) throw new Error('Changed global action');
            actions[id] = exclusive;
            continue;
        }
        if (
            old.method !== next.method ||
            stateKey([...old.transports].sort()) !== stateKey([...next.transports].sort())
        )
            throw new Error('Changed action transport');
        if (old.target === null || next.target === null) {
            if (old.target !== null || next.target !== null || old.url !== next.url)
                throw new Error('Changed global action');
            actions[id] = next;
            continue;
        }
        if (
            old.target.input !== next.target.input ||
            old.target.valueType !== next.target.valueType
        )
            throw new Error('Changed action target');
        const values = [...old.target.values];
        for (const item of next.target.values) {
            const previous = values.find((entry) => entry.value === item.value);
            if (previous && previous.url !== item.url) throw new Error('Conflicting action URL');
            if (!previous) values.push(item);
        }
        actions[id] = { ...next, target: { ...next.target, values } };
    }
    return freeze(actions);
}
function combineRecords(left: RowRecords, right: RowRecords): RowRecords {
    if (left.mode === 'unidentified' && right.mode === 'unidentified') return right;
    if (left.mode === 'identified' && right.mode === 'identified')
        return { mode: 'identified', rows: [...left.rows, ...right.rows] };
    if (left.mode === 'revisioned' && right.mode === 'revisioned')
        return { mode: 'revisioned', format: right.format, rows: [...left.rows, ...right.rows] };
    throw new Error('Changed record mode');
}
export function acceptWindow(
    previous: WindowResult | null,
    incoming: WindowResult,
    append: boolean,
    enabled: boolean,
): WindowResult {
    if (!enabled) return incoming;
    if (!append || !previous?.window)
        return {
            ...incoming,
            window: {
                start: incoming.page.requested,
                consumed: [boundary(incoming.page.requested)],
                stopped: false,
            },
        };
    if (
        stateKey(previous.page.definition) !== stateKey(incoming.page.definition) ||
        !sameChannel(previous.page.metadata.channel, incoming.page.metadata.channel)
    )
        throw new Error('Changed window capabilities');
    if (previous.window.consumed.includes(boundary(incoming.page.requested)))
        return { ...previous, window: { ...previous.window, stopped: true } };
    const records = combineRecords(previous.page.metadata.records, incoming.page.metadata.records);
    const actions = combineActions(previous.page.actions, incoming.page.actions);
    return freeze({
        ...incoming,
        window: {
            ...previous.window,
            consumed: [...previous.window.consumed, boundary(incoming.page.requested)],
        },
        data: [...previous.data, ...incoming.data],
        page: {
            ...incoming.page,
            ...(actions ? { actions } : {}),
            data: [...previous.page.data, ...incoming.page.data],
            metadata: { ...incoming.page.metadata, records },
        },
    });
}
export function replaceOccurrences(
    result: WindowResult,
    update: RowUpdate,
    decoded: readonly unknown[],
): WindowResult {
    const current = result.page.metadata.records;
    const incoming = update.records.rows[0];
    if (current.mode === 'unidentified' || !incoming || current.mode !== update.records.mode)
        return result;
    const indexes = new Set<number>();
    let records: RowRecords = current;
    if (current.mode === 'revisioned' && update.records.mode === 'revisioned') {
        const next = update.records.rows[0];
        if (!next) return result;
        records = {
            ...current,
            rows: current.rows.map((row, index) => {
                if (row.id !== next.id || !newer(next.revision, row.revision)) return row;
                indexes.add(index);
                return next;
            }),
        };
    } else
        current.rows.forEach((row, index) => {
            if (row.id === incoming.id) indexes.add(index);
        });
    if (!indexes.size) return result;
    return Object.freeze({
        ...result,
        data: Object.freeze(
            result.data.map((row, index) => (indexes.has(index) ? decoded[0] : row)),
        ),
        page: Object.freeze({
            ...result.page,
            data: Object.freeze(
                result.page.data.map((row, index) => (indexes.has(index) ? update.data[0] : row)),
            ),
            metadata: Object.freeze({ ...result.page.metadata, records: freeze(records) }),
        }),
    });
}
