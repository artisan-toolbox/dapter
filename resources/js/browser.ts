import { router } from '@inertiajs/core';
import { usePage } from '@inertiajs/vue3';
import { effectScope, nextTick, toValue, watch, type MaybeRefOrGetter } from 'vue';
import type {
    AdapterDefinition,
    BrowserStatePolicy,
    SourceRequestedStateV2 as RequestedStateV2,
} from './protocol-v2.js';
import { stateKey } from './requested-state.js';
import {
    readSnapshot,
    removeSnapshot,
    shareToken,
    sweepSnapshots,
    writeSnapshot,
} from './browser-snapshots.js';

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
    id: string | undefined;
    enabled: boolean;
    scrollContainer?: MaybeRefOrGetter<HTMLElement | null>;
    onRestore: (snapshot: RequestedStateV2 | null) => void;
};
type Owner = {
    key: string;
    alive: boolean;
    settled: string | null;
    lastUrl: string;
    guard: (() => boolean) | undefined;
};
export class BrowserKeyConflict extends Error {
    constructor(key: string) {
        super(
            `Another URL-enabled adapter already uses the query key "${key}". Choose an independent adapter id or state key.`,
        );
    }
}

const owners = new Map<string, Owner>();
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
): Promise<boolean> {
    let committed = false;
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
                committed = true;
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
                committed = true;
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
    return committed;
}

export function createBrowser(options: Options) {
    let disposed = false;
    let popped = false;
    let restoredPosition: Position | undefined;
    let stopHistory: (() => void) | undefined;
    let configured = false;
    let policy: BrowserStatePolicy | undefined;
    let definition: AdapterDefinition | undefined;
    let defaults: string | undefined;
    let lastSignature: string | undefined;
    const owner: Owner = {
        key: '',
        alive: false,
        settled: null,
        lastUrl: '',
        guard: undefined,
    };
    function container(): HTMLElement | null {
        return options.scrollContainer === undefined ? null : toValue(options.scrollContainer);
    }
    function positionKey(url: string): string {
        return `${options.source}\n${owner.key}\n${url}`;
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
    function candidate(): RequestedStateV2 | null {
        if (!owner.alive || !policy || !definition) return null;
        const state = readSnapshot(owner.settled, options.source, policy, definition);
        return state && stateKey(state) !== defaults ? state : null;
    }
    function restore(): void {
        if (!popped || !owner.alive) return;
        popped = false;
        const url = new URL(window.location.href);
        const raw = url.searchParams.get(owner.key);
        restoredPosition = positions.get(positionKey(url.href));
        const same = owner.settled === raw && owner.guard?.();
        owner.settled = raw;
        owner.lastUrl = url.href;
        const snapshot = candidate();
        if (!same || (raw !== null && !snapshot)) {
            lastSignature = undefined;
            options.onRestore(snapshot);
        } else
            void nextTick().then(() => {
                if (owner.alive) restorePosition();
            });
    }
    return {
        version: () => policy?.version,
        changed(next: BrowserStatePolicy | undefined): boolean {
            return configured && (policy?.key !== next?.key || policy?.version !== next?.version);
        },
        configure(
            next: BrowserStatePolicy | undefined,
            capabilities: AdapterDefinition,
            defaultState: RequestedStateV2 | null,
        ) {
            configured = true;
            policy = next;
            definition = capabilities;
            if (defaultState) defaults = stateKey(defaultState);
            if (disposed || !options.enabled || !policy || typeof window === 'undefined') return;
            if (!owner.alive) {
                const key =
                    options.id === undefined || options.id === options.source
                        ? policy.key
                        : `${policy.key}.${options.id}`;
                if (owners.has(key)) throw new BrowserKeyConflict(key);
                owner.key = key;
                owner.alive = true;
                owner.lastUrl = window.location.href;
                restoredPosition = positions.get(positionKey(owner.lastUrl));
                owner.settled = new URL(owner.lastUrl).searchParams.get(key);
                owners.set(key, owner);
                observe();
                window.addEventListener('popstate', onPop);
                stopHistory = router.on('navigate', restore);
            }
            if (defaultState) sweepSnapshots(options.source, policy);
        },
        candidate,
        share(snapshot: RequestedStateV2): string | null {
            if (!owner.alive || !policy || !defaults) return null;
            const target = new URL(window.location.href);
            for (const key of owners.keys()) target.searchParams.delete(key);
            if (stateKey(snapshot) !== defaults)
                target.searchParams.set(
                    owner.key,
                    shareToken(options.source, policy.version, snapshot),
                );
            return target.href;
        },
        async publish(
            snapshot: RequestedStateV2 | null,
            reason: Reason,
            current: () => boolean,
        ): Promise<void> {
            if (disposed || !current()) return;
            save();
            owner.guard = current;
            if (owner.alive && policy && definition) {
                const previous = owner.settled;
                const signature = stateKey(snapshot);
                const isDefault = snapshot === null || signature === defaults;
                const existing =
                    !isDefault && previous !== null && !previous.startsWith('s1.')
                        ? readSnapshot(previous, options.source, policy, definition)
                        : null;
                let created: string | null = null;
                let value: string | null = null;
                if (!isDefault && snapshot) {
                    if (existing && stateKey(existing) === signature) value = previous;
                    else if (lastSignature !== signature || previous?.startsWith('s1.'))
                        value = created = writeSnapshot(options.source, policy, snapshot);
                }
                const committed = await publish(
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
                if (!committed) removeSnapshot(created, options.source);
                else {
                    lastSignature = signature;
                    if (isDefault || (value === null && !existing))
                        removeSnapshot(previous, options.source);
                }
            }
            await nextTick();
            if (disposed || !current()) return;
            if (reason === 'history' || reason === 'initial') restorePosition();
            else if (!['reload', 'append'].includes(reason)) {
                const element = container();
                if (element) {
                    element.scrollTop = 0;
                    element.scrollLeft = 0;
                }
            }
        },
        async reset(): Promise<void> {
            restoredPosition = undefined;
            for (const position of positions.keys())
                if (position.startsWith(`${options.source}\n${owner.key}\n`))
                    positions.delete(position);
            if (!owner.alive) return;
            const previous = owner.settled;
            if (await publish(owner, null, true, () => !disposed && owner.alive)) {
                removeSnapshot(previous, options.source);
                lastSignature = undefined;
            }
        },
        dispose() {
            if (disposed) return;
            save();
            disposed = true;
            owner.alive = false;
            if (owners.get(owner.key) === owner) owners.delete(owner.key);
            if (typeof window !== 'undefined') window.removeEventListener('popstate', onPop);
            stopHistory?.();
            releaseObserver();
        },
    };
}
