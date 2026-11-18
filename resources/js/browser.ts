import { router } from '@inertiajs/core';
import { usePage } from '@inertiajs/vue3';
import { effectScope, nextTick, toValue, watch, type MaybeRefOrGetter } from 'vue';
import {
    parseRequestedV2,
    type SourceRequestedStateV2 as RequestedStateV2,
} from './protocol-v2.js';

type Reason =
    | 'append'
    | 'initial'
    | 'history'
    | 'reload'
    | 'search'
    | 'criteria'
    | 'page'
    | 'size'
    | 'replace'
    | 'reset';
type Position = { top: number; left: number };
type Options = {
    source: string;
    id: string;
    enabled: boolean;
    scrollContainer?: MaybeRefOrGetter<HTMLElement | null>;
    onRestore: (snapshot: RequestedStateV2 | null) => void;
    onInvalid: () => void;
};
type Owner = {
    source: string;
    key: string;
    alive: boolean;
    settled: string | null;
    lastUrl: string;
    guard: (() => boolean) | undefined;
};
const owners = new Map<string, Owner>();
const retired = new Map<string, string>();
const positions = new Map<string, Position>();
let queue: Promise<void> = Promise.resolve();
let navigation = 0;
let authoritativeUrl: string | null = null;
let expectedUrl: string | null = null;
let pendingWrites = 0;
let observer: ReturnType<typeof effectScope> | null = null;
let releaseNavigation: (() => void) | null = null;

function absolute(value: string): string {
    return new URL(value, window.location.href).href;
}
function observe(): void {
    if (observer || typeof window === 'undefined') return;
    authoritativeUrl = window.location.href;
    const page = usePage();
    observer = effectScope(true);
    observer.run(() =>
        watch(
            () => page.url,
            (url) => {
                if (!url) return;
                const actual = absolute(url);
                if (actual !== expectedUrl) {
                    authoritativeUrl = actual;
                    navigation++;
                }
            },
            { flush: 'sync' },
        ),
    );
    releaseNavigation = router.on('navigate', (event: { detail: { page: { url: string } } }) => {
        const actual = absolute(event.detail.page.url);
        if (actual !== expectedUrl) {
            authoritativeUrl = actual;
            navigation++;
        }
    });
}
function releaseObserver(): void {
    if (owners.size || pendingWrites) return;
    observer?.stop();
    observer = null;
    releaseNavigation?.();
    releaseNavigation = null;
    authoritativeUrl = null;
}
function patch(url: string, key: string, value: string | null): string {
    const target = new URL(url);
    if (value === null) target.searchParams.delete(key);
    else target.searchParams.set(key, value);
    return target.href;
}
function clientVisit(url: string, replace: boolean): Promise<void> {
    expectedUrl = url;
    return new Promise((resolve) => {
        try {
            router[replace ? 'replace' : 'push']({
                url,
                preserveState: true,
                preserveScroll: true,
                flash: (flash: Record<string, unknown>) => flash,
                onFinish: () => resolve(),
            });
        } catch {
            resolve();
        }
    });
}
async function publish(
    owner: Owner,
    value: string | null,
    replace: boolean,
    current: () => boolean,
): Promise<void> {
    const epoch = navigation;
    pendingWrites++;
    const task = queue
        .then(async () => {
            if (
                !owner.alive ||
                owners.get(owner.key) !== owner ||
                !current() ||
                epoch !== navigation
            )
                return;
            const target = patch(window.location.href, owner.key, value);
            if (target === window.location.href) {
                owner.settled = value;
                owner.lastUrl = target;
                return;
            }
            await clientVisit(target, replace);
            if (
                owner.alive &&
                owners.get(owner.key) === owner &&
                current() &&
                epoch === navigation &&
                window.location.href === target
            ) {
                owner.settled = value;
                owner.lastUrl = target;
                authoritativeUrl = target;
                return;
            }
            let written = target;
            let correctionEpoch = epoch;
            while (window.location.href === written) {
                const live = owners.get(owner.key);
                const corrected =
                    correctionEpoch !== navigation && authoritativeUrl !== null
                        ? authoritativeUrl
                        : patch(window.location.href, owner.key, live?.settled ?? owner.settled);
                if (corrected === window.location.href) break;
                correctionEpoch = navigation;
                written = corrected;
                await clientVisit(corrected, true);
                if (correctionEpoch === navigation) break;
            }
        })
        .catch(() => {})
        .finally(() => {
            expectedUrl = null;
            pendingWrites--;
            releaseObserver();
        });
    queue = task;
    await task;
}
function decode(
    url: URL,
    key: string,
    source: string,
): { snapshot: RequestedStateV2 | null; invalid: boolean } {
    const raw = url.searchParams.get(key);
    if (raw === null) return { snapshot: null, invalid: false };
    try {
        const value: unknown = JSON.parse(raw);
        if (
            typeof value !== 'object' ||
            value === null ||
            Array.isArray(value) ||
            !('version' in value) ||
            value.version !== 2 ||
            !('source' in value) ||
            value.source !== source ||
            !('state' in value) ||
            Object.keys(value).length !== 3
        )
            return { snapshot: null, invalid: true };
        return { snapshot: parseRequestedV2(value.state), invalid: false };
    } catch {
        return { snapshot: null, invalid: true };
    }
}
function encode(source: string, state: RequestedStateV2): string {
    return JSON.stringify({ version: 2, source, state });
}
export function createBrowser(options: Options): {
    start: () => { snapshot: RequestedStateV2 | null; invalid: boolean };
    publish: (
        snapshot: RequestedStateV2 | null,
        reason: Reason,
        current: () => boolean,
    ) => Promise<void>;
    reset: () => Promise<void>;
    dispose: () => void;
} {
    let started = false;
    let disposed = false;
    let popped = false;
    let restoredPosition: Position | undefined;
    let stopHistory: (() => void) | undefined;
    let interacted = false;
    const key = `dapter[${options.id}]`;
    const owner: Owner = {
        source: options.source,
        key,
        alive: false,
        settled: null,
        lastUrl: '',
        guard: undefined,
    };
    function container(): HTMLElement | null {
        return options.scrollContainer === undefined ? null : toValue(options.scrollContainer);
    }
    function positionKey(url: string): string {
        return `${options.source}\n${key}\n${url}`;
    }
    function save(): void {
        const element = container();
        if (element && owner.lastUrl)
            positions.set(positionKey(owner.lastUrl), {
                top: element.scrollTop,
                left: element.scrollLeft,
            });
        while (positions.size > 200) positions.delete(positions.keys().next().value ?? '');
    }
    function restorePosition(): void {
        const element = container();
        if (element && restoredPosition) {
            element.scrollTop = restoredPosition.top;
            element.scrollLeft = restoredPosition.left;
        }
        restoredPosition = undefined;
    }
    function onPop(): void {
        save();
        popped = true;
    }
    function restore(): void {
        if (!popped || !owner.alive) return;
        popped = false;
        const url = new URL(window.location.href);
        const candidate = decode(url, key, options.source);
        restoredPosition = positions.get(positionKey(url.href));
        const same = owner.settled === url.searchParams.get(key) && owner.guard?.();
        owner.settled = url.searchParams.get(key);
        owner.lastUrl = url.href;
        interacted = owner.settled !== null;
        if (candidate.invalid) options.onInvalid();
        else if (!same) options.onRestore(candidate.snapshot);
        else
            void nextTick().then(() => {
                if (owner.alive) restorePosition();
            });
    }
    return {
        start() {
            if (started || disposed || typeof window === 'undefined')
                return { snapshot: null, invalid: false };
            started = true;
            if (!options.enabled) return { snapshot: null, invalid: false };
            const prior = owners.get(key);
            if (prior?.alive)
                throw new Error('URL-enabled adapters require independent identifiers.');
            owner.alive = true;
            owner.lastUrl = window.location.href;
            restoredPosition = positions.get(positionKey(owner.lastUrl));
            owner.settled = new URL(owner.lastUrl).searchParams.get(key);
            owners.set(key, owner);
            observe();
            window.addEventListener('popstate', onPop);
            stopHistory = router.on('navigate', restore);
            const candidate = decode(new URL(owner.lastUrl), key, options.source);
            const previousSource = retired.get(key);
            retired.delete(key);
            if (
                candidate.invalid &&
                previousSource !== undefined &&
                previousSource !== options.source
            ) {
                owner.settled = null;
                return { snapshot: null, invalid: false };
            }
            return candidate;
        },
        async publish(snapshot, reason, current) {
            if (disposed || !current()) return;
            save();
            owner.guard = current;
            if (options.enabled && owner.alive && typeof window !== 'undefined') {
                if (!['initial', 'history', 'reload', 'append'].includes(reason))
                    interacted = reason !== 'reset';
                const absent =
                    ['initial', 'history', 'reload', 'append'].includes(reason) &&
                    owner.settled === null &&
                    !interacted;
                const value = snapshot === null || absent ? null : encode(options.source, snapshot);
                await publish(
                    owner,
                    value,
                    [
                        'initial',
                        'history',
                        'reload',
                        'search',
                        'replace',
                        'reset',
                        'append',
                    ].includes(reason),
                    current,
                );
            }
            await nextTick();
            if (disposed || !current()) return;
            if (reason === 'history' || reason === 'initial') restorePosition();
            else if (!['initial', 'reload', 'append'].includes(reason)) {
                const element = container();
                if (element) {
                    element.scrollTop = 0;
                    element.scrollLeft = 0;
                }
            }
        },
        reset() {
            interacted = false;
            restoredPosition = undefined;
            for (const position of positions.keys())
                if (position.startsWith(`${options.source}\n${key}\n`)) positions.delete(position);
            owner.settled = null;
            if (typeof window === 'undefined' || !options.enabled || !owner.alive)
                return Promise.resolve();
            return publish(owner, null, true, () => !disposed && owner.alive);
        },
        dispose() {
            if (disposed) return;
            save();
            disposed = true;
            owner.alive = false;
            if (owners.get(key) === owner) owners.delete(key);
            retired.set(key, options.source);
            queueMicrotask(() => {
                if (retired.get(key) === options.source) retired.delete(key);
            });
            if (typeof window !== 'undefined') window.removeEventListener('popstate', onPop);
            stopHistory?.();
            releaseObserver();
        },
    };
}
