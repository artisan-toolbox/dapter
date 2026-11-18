import { freeze, object, validateJson, type ReadonlyDeep } from './protocol.js';

export type RowId = number | string;
export type RowRecords =
    | { readonly mode: 'unidentified' }
    | { readonly mode: 'identified'; readonly rows: readonly { readonly id: RowId }[] }
    | {
          readonly mode: 'revisioned';
          readonly format: 'uint64-decimal';
          readonly rows: readonly { readonly id: RowId; readonly revision: string }[];
      };
export type ChannelDescriptor = {
    readonly type: 'private' | 'public';
    readonly name: string;
    readonly event: '.dapter.updated';
};
export type AdapterMetadata = {
    readonly records: RowRecords;
    readonly channel: ChannelDescriptor | null;
};
function exact(value: Record<string, unknown>, keys: readonly string[]): void {
    if (Object.keys(value).length !== keys.length || keys.some((key) => !Object.hasOwn(value, key)))
        throw new Error('Invalid record properties');
}
function unicode(value: string): boolean {
    return !/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/u.test(value);
}
export function parseRows(value: unknown): readonly unknown[] {
    if (!Array.isArray(value) || Object.keys(value).length !== value.length)
        throw new Error('Invalid rows');
    function check(input: unknown, depth: number): void {
        if (depth > 100) throw new Error('Invalid row depth');
        if (typeof input === 'string' && !unicode(input)) throw new Error('Invalid Unicode');
        if (Array.isArray(input)) {
            if (Object.keys(input).length !== input.length) throw new Error('Sparse rows');
            for (const item of input) check(item, depth + 1);
        } else if (input !== null && typeof input === 'object') {
            for (const [key, item] of Object.entries(input)) {
                if (!unicode(key)) throw new Error('Invalid Unicode');
                check(item, depth + 1);
            }
        }
    }
    for (const row of value) check(row, 0);
    validateJson(value);
    return freeze(value);
}
export function decodeRows<Row>(
    rows: readonly unknown[],
    decoder: (value: unknown) => Row,
): readonly ReadonlyDeep<Row>[];
export function decodeRows(
    rows: readonly unknown[],
    decoder: ((value: unknown) => unknown) | undefined,
): readonly unknown[];
export function decodeRows(
    rows: readonly unknown[],
    decoder: ((value: unknown) => unknown) | undefined,
): readonly unknown[] {
    return parseRows(rows.map((row) => (decoder ? decoder(row) : row)));
}
function id(value: unknown): RowId {
    if (typeof value === 'number' && Number.isSafeInteger(value)) return value === 0 ? 0 : value;
    if (typeof value === 'string' && value.length > 0 && unicode(value)) return value;
    throw new Error('Invalid row identity');
}
function revision(value: unknown): string {
    if (
        typeof value === 'string' &&
        /^(0|[1-9][0-9]*)$/.exec(value)?.[0] === value &&
        (value.length < 20 || (value.length === 20 && value <= '18446744073709551615'))
    )
        return value;
    throw new Error('Invalid row revision');
}
export function newer(left: string, right: string): boolean {
    return left.length > right.length || (left.length === right.length && left > right);
}
export function parseRecords(value: unknown, length: number): RowRecords {
    const input = object(value);
    if (input.mode === 'unidentified') {
        exact(input, ['mode']);
        return Object.freeze({ mode: 'unidentified' });
    }
    if (input.mode !== 'identified' && input.mode !== 'revisioned')
        throw new Error('Invalid record mode');
    exact(input, input.mode === 'identified' ? ['mode', 'rows'] : ['mode', 'format', 'rows']);
    if (
        !Array.isArray(input.rows) ||
        input.rows.length !== length ||
        Object.keys(input.rows).length !== length
    )
        throw new Error('Misaligned records');
    const seen = new Set<RowId>();
    const rows = input.rows.map((value: unknown) => {
        const entry = object(value);
        exact(entry, input.mode === 'identified' ? ['id'] : ['id', 'revision']);
        const identity = id(entry.id);
        if (seen.has(identity)) throw new Error('Duplicate identity');
        seen.add(identity);
        return {
            id: identity,
            ...(input.mode === 'revisioned' ? { revision: revision(entry.revision) } : {}),
        };
    });
    if (input.mode === 'identified') return freeze({ mode: 'identified', rows });
    if (input.format !== 'uint64-decimal') throw new Error('Invalid revision format');
    return freeze({
        mode: 'revisioned',
        format: 'uint64-decimal',
        rows: rows.map((row) => ({ id: row.id, revision: revision(row.revision) })),
    });
}
export function parseChannel(value: unknown): ChannelDescriptor | null {
    if (value === null) return null;
    const input = object(value);
    exact(input, ['type', 'name', 'event']);
    if (
        (input.type !== 'private' && input.type !== 'public') ||
        typeof input.name !== 'string' ||
        input.name.length > 128 ||
        /^[A-Za-z0-9_=-]{1,64}(?:\.[A-Za-z0-9_=-]{1,64})*$/.exec(input.name)?.[0] !== input.name ||
        /^(private-|presence-)/.test(input.name) ||
        input.event !== '.dapter.updated'
    )
        throw new Error('Invalid channel');
    return Object.freeze({ type: input.type, name: input.name, event: input.event });
}
export function sameChannel(
    left: ChannelDescriptor | null,
    right: ChannelDescriptor | null,
): boolean {
    return (
        left === right ||
        (left !== null &&
            right !== null &&
            left.type === right.type &&
            left.name === right.name &&
            left.event === right.event)
    );
}
export function parseMetadata(value: unknown, length: number): AdapterMetadata {
    const input = object(value);
    exact(input, ['records', 'channel']);
    const records = parseRecords(input.records, length),
        channel = parseChannel(input.channel);
    if (channel !== null && records.mode === 'unidentified')
        throw new Error('Unidentified live rows');
    return freeze({ records, channel });
}
export type RowUpdate = {
    readonly eventId: string;
    readonly data: readonly unknown[];
    readonly records: Exclude<RowRecords, { mode: 'unidentified' }>;
    readonly bytes: number;
};
export function parseUpdate(
    value: unknown,
    source: string,
    channel: ChannelDescriptor,
): RowUpdate | null {
    const input = object(value);
    if (typeof input.source === 'string' && input.source !== source) return null;
    const descriptor = parseChannel(input.channel);
    if (!sameChannel(descriptor, channel)) return null;
    exact(input, ['version', 'eventId', 'source', 'channel', 'data', 'records']);
    if (
        input.version !== 1 ||
        input.source !== source ||
        typeof input.eventId !== 'string' ||
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.exec(
            input.eventId,
        )?.[0] !== input.eventId
    )
        throw new Error('Invalid update');
    const data = parseRows(input.data),
        records = parseRecords(input.records, data.length);
    if (data.length !== 1 || records.mode === 'unidentified')
        throw new Error('Invalid update rows');
    const bytes = new TextEncoder().encode(JSON.stringify(input)).length;
    if (bytes > 65536) throw new Error('Oversized update');
    return freeze({ eventId: input.eventId, data, records, bytes });
}
