import { afterEach, expect, it, vi } from 'vite-plus/test';
import { effectScope, nextTick, shallowRef } from 'vue';
import { observeInfinite } from '../../resources/js/infinite-observer.js';
import type { InfiniteControls } from '@artisan-toolbox/dapter';
const cleanups: (() => void)[] = [];
afterEach(() => {
    cleanups.splice(0).forEach((stop) => stop());
    vi.unstubAllGlobals();
});
function setup(axis: 'x' | 'y' = 'y') {
    let top = 50,
        left = 50,
        contained = true;
    const sentinel = {
        getBoundingClientRect: () => ({ top, bottom: top + 2, left, right: left + 2 }),
    } as HTMLElement;
    const createRoot = () =>
        ({
            scrollTop: 0,
            scrollLeft: 0,
            contains: (value: unknown) => contained && value === sentinel,
            getBoundingClientRect: () => ({ top: 0, bottom: 100, left: 0, right: 100 }),
        }) as HTMLElement;
    const root = createRoot();
    const instances: { emit: (visible: boolean) => void; disconnect: ReturnType<typeof vi.fn> }[] =
        [];
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            disconnect = vi.fn();
            observe = vi.fn();
            constructor(callback: IntersectionObserverCallback) {
                instances.push({
                    disconnect: this.disconnect,
                    emit: (visible) =>
                        callback(
                            [
                                {
                                    target: sentinel,
                                    isIntersecting: visible,
                                    boundingClientRect: sentinel.getBoundingClientRect(),
                                    intersectionRatio: visible ? 1 : 0,
                                    intersectionRect: sentinel.getBoundingClientRect(),
                                    rootBounds: root.getBoundingClientRect(),
                                    time: 0,
                                },
                            ],
                            this as unknown as IntersectionObserver,
                        ),
                });
            }
        },
    );
    const rootRef = shallowRef<HTMLElement | null>(null),
        sentinelRef = shallowRef<HTMLElement | null>(sentinel),
        ready = shallowRef(false),
        status = shallowRef<InfiniteControls['status']>('idle');
    const append = vi.fn(async () => ({ status: 'success' as const }));
    const scope = effectScope();
    scope.run(() => {
        const stop = observeInfinite(
            { root: rootRef, sentinel: sentinelRef, axis },
            { ready: () => ready.value, status: () => status.value, append },
        );
        cleanups.push(stop);
    });
    cleanups.push(() => scope.stop());
    return {
        root,
        createRoot,
        rootRef,
        sentinelRef,
        ready,
        status,
        append,
        instances,
        setContained: (value: boolean) => {
            contained = value;
        },
        move: (x: number, y: number) => {
            left = x;
            top = y;
        },
    };
}
async function flush() {
    for (let i = 0; i < 10; i++) await Promise.resolve();
    await nextTick();
}
it.each(['y', 'x'] as const)(
    'limits %s visibility episodes to two appends and rearms only after chosen-axis movement',
    async (axis) => {
        const state = setup(axis);
        state.rootRef.value = state.root;
        state.ready.value = true;
        await flush();
        const observer = state.instances[0];
        if (!observer) throw new Error('Missing observer');
        observer.emit(true);
        observer.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(2);
        observer.emit(false);
        observer.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(2);
        if (axis === 'x') state.root.scrollTop = 5;
        else state.root.scrollLeft = 5;
        observer.emit(false);
        observer.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(2);
        if (axis === 'x') state.root.scrollLeft = 5;
        else state.root.scrollTop = 5;
        observer.emit(false);
        observer.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(4);
        observer.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(4);
    },
);
it('waits for contained refs and settlement, ignores replaced callbacks and preserves failure pause', async () => {
    const state = setup();
    state.ready.value = true;
    await flush();
    expect(state.instances).toHaveLength(0);
    state.rootRef.value = state.root;
    await flush();
    const observer = state.instances[0];
    if (!observer) throw new Error('Missing observer');
    state.status.value = 'paused';
    observer.emit(true);
    await flush();
    expect(state.append).not.toHaveBeenCalled();
    state.rootRef.value = null;
    await flush();
    expect(observer.disconnect).toHaveBeenCalledOnce();
    state.rootRef.value = state.root;
    await flush();
    observer.emit(true);
    await flush();
    expect(state.append).not.toHaveBeenCalled();
    const current = state.instances[1];
    if (!current) throw new Error('Missing replacement observer');
    current.emit(true);
    await flush();
    expect(state.append).not.toHaveBeenCalled();
    state.status.value = 'idle';
    current.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledTimes(2);
    state.ready.value = false;
    await flush();
    current.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledTimes(2);
});
it('checks chosen-axis geometry after each settled append and stops when content grows out of view', async () => {
    const state = setup('x');
    state.append.mockImplementation(async () => {
        state.move(120, 50);
        return { status: 'success' };
    });
    state.rootRef.value = state.root;
    state.ready.value = true;
    await flush();
    state.instances[0]?.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledOnce();
});
it('leaves manual owners usable without IntersectionObserver', async () => {
    const state = setup();
    vi.stubGlobal('IntersectionObserver', undefined);
    state.rootRef.value = state.root;
    state.ready.value = true;
    await flush();
    expect(state.instances).toHaveLength(0);
    expect((await state.append()).status).toBe('success');
});
it('resumes the current visible observer after a replaced observer finishes its reserved append', async () => {
    const state = setup();
    let finish: (() => void) | undefined;
    state.append.mockImplementationOnce(() => {
        state.status.value = 'loading';
        return new Promise((resolve) => {
            finish = () => {
                state.status.value = 'idle';
                resolve({ status: 'success' });
            };
        });
    });
    state.rootRef.value = state.root;
    state.ready.value = true;
    await flush();
    state.instances[0]?.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledOnce();
    state.rootRef.value = null;
    await flush();
    state.rootRef.value = state.root;
    await flush();
    state.instances[1]?.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledOnce();
    finish?.();
    await flush();
    expect(state.append).toHaveBeenCalledTimes(2);
    state.instances[1]?.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledTimes(2);
});
it('observes the same refs when DOM containment becomes valid and rejects disconnected callbacks', async () => {
    const state = setup();
    let mutation: (() => void) | undefined;
    const detachMutation = vi.fn();
    vi.stubGlobal(
        'MutationObserver',
        class {
            constructor(callback: () => void) {
                mutation = callback;
            }
            observe() {}
            disconnect = detachMutation;
        },
    );
    state.setContained(false);
    state.rootRef.value = state.root;
    state.ready.value = true;
    await flush();
    expect(state.instances).toHaveLength(0);
    state.setContained(true);
    mutation?.();
    expect(state.instances).toHaveLength(1);
    const old = state.instances[0];
    state.setContained(false);
    mutation?.();
    expect(old?.disconnect).toHaveBeenCalledOnce();
    old?.emit(true);
    await flush();
    expect(state.append).not.toHaveBeenCalled();
    state.setContained(true);
    mutation?.();
    old?.emit(true);
    await flush();
    expect(state.append).not.toHaveBeenCalled();
    state.instances[1]?.emit(true);
    await flush();
    expect(state.append).toHaveBeenCalledTimes(2);
    state.ready.value = false;
    await flush();
    expect(detachMutation).toHaveBeenCalledOnce();
});
it.each([
    ['x', 1],
    ['x', 2],
    ['y', 1],
    ['y', 2],
] as const)(
    'preserves the %s automatic budget after %i appends when replacement roots have different stationary offsets',
    async (axis, spent) => {
        const state = setup(axis);
        if (spent === 1)
            state.append.mockImplementationOnce(async () => {
                state.move(120, 120);
                return { status: 'success' };
            });
        state.rootRef.value = state.root;
        state.ready.value = true;
        await flush();
        state.instances[0]?.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(spent);
        state.instances[0]?.emit(false);
        const replacement = state.createRoot();
        if (axis === 'x') replacement.scrollLeft = 100;
        else replacement.scrollTop = 100;
        state.move(50, 50);
        state.rootRef.value = replacement;
        await flush();
        state.instances[1]?.emit(true);
        await flush();
        expect(state.root.scrollTop).toBe(0);
        expect(state.root.scrollLeft).toBe(0);
        expect(replacement.scrollTop).toBe(axis === 'y' ? 100 : 0);
        expect(replacement.scrollLeft).toBe(axis === 'x' ? 100 : 0);
        expect(state.append).toHaveBeenCalledTimes(2);
        state.instances[1]?.emit(false);
        if (axis === 'x') replacement.scrollLeft = 105;
        else replacement.scrollTop = 105;
        state.instances[1]?.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(4);
    },
);
it.each([
    ['x', 1],
    ['x', 2],
    ['y', 1],
    ['y', 2],
] as const)(
    'preserves the %s automatic budget after %i appends when the same sentinel reconnects',
    async (axis, spent) => {
        const state = setup(axis);
        let mutation: (() => void) | undefined;
        vi.stubGlobal(
            'MutationObserver',
            class {
                constructor(callback: () => void) {
                    mutation = callback;
                }
                observe() {}
                disconnect() {}
            },
        );
        if (spent === 1)
            state.append.mockImplementationOnce(async () => {
                state.move(120, 120);
                return { status: 'success' };
            });
        state.rootRef.value = state.root;
        state.ready.value = true;
        await flush();
        state.instances[0]?.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(spent);
        state.instances[0]?.emit(false);
        state.setContained(false);
        mutation?.();
        if (axis === 'x') state.root.scrollLeft = 100;
        else state.root.scrollTop = 100;
        state.move(50, 50);
        state.setContained(true);
        mutation?.();
        state.instances[1]?.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(2);
        state.instances[1]?.emit(false);
        if (axis === 'x') state.root.scrollLeft = 105;
        else state.root.scrollTop = 105;
        state.instances[1]?.emit(true);
        await flush();
        expect(state.append).toHaveBeenCalledTimes(4);
    },
);
