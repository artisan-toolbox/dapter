import { toValue, watch } from 'vue';
import type { AsyncOutcome, InfiniteControls, InfiniteOptions } from './adapter.js';

type Observation = Exclude<InfiniteOptions, boolean>;
export function observeInfinite(
    options: Observation,
    owner: {
        readonly ready: () => boolean;
        readonly status: () => InfiniteControls['status'];
        readonly append: () => Promise<AsyncOutcome>;
    },
): () => void {
    const axis = options.axis ?? 'y';
    let version = 0;
    let credits = 2;
    let visible = false;
    let episodeOffset: number | null = null;
    let left = false;
    let running: number | null = null;
    let resume = () => {};
    let disconnect = () => {};
    function offset(root: HTMLElement): number {
        return axis === 'x' ? root.scrollLeft : root.scrollTop;
    }
    function contains(root: HTMLElement, sentinel: HTMLElement): boolean {
        const bounds = root.getBoundingClientRect(),
            target = sentinel.getBoundingClientRect();
        return axis === 'x'
            ? target.right >= bounds.left && target.left <= bounds.right
            : target.bottom >= bounds.top && target.top <= bounds.bottom;
    }
    async function fill(root: HTMLElement, sentinel: HTMLElement, active: number): Promise<void> {
        if (running === active || !visible || credits === 0 || owner.status() !== 'idle') return;
        running = active;
        try {
            while (
                active === version &&
                owner.ready() &&
                toValue(options.root) === root &&
                toValue(options.sentinel) === sentinel &&
                root.contains(sentinel) &&
                visible &&
                credits > 0 &&
                owner.status() === 'idle'
            ) {
                credits--;
                const outcome = await owner.append();
                if (active !== version || outcome.status !== 'success') break;
                visible = contains(root, sentinel);
                if (!visible) left = true;
            }
        } finally {
            if (running === active) running = null;
        }
    }
    const stop = watch(
        () => [toValue(options.root), toValue(options.sentinel), owner.ready()] as const,
        ([root, sentinel, ready]) => {
            disconnect();
            disconnect = () => {};
            const active = ++version;
            running = null;
            resume = () => {};
            visible = false;
            if (!ready) {
                credits = 2;
                episodeOffset = null;
                left = false;
                return;
            }
            if (!root || !sentinel || typeof IntersectionObserver === 'undefined') return;
            let observation: IntersectionObserver | null = null;
            let connection = 0;
            function connect(): void {
                if (
                    active !== version ||
                    !owner.ready() ||
                    toValue(options.root) !== root ||
                    toValue(options.sentinel) !== sentinel ||
                    !root ||
                    !sentinel
                )
                    return;
                if (!root.contains(sentinel)) {
                    connection++;
                    observation?.disconnect();
                    observation = null;
                    visible = false;
                    return;
                }
                if (observation) return;
                episodeOffset = offset(root);
                left = false;
                const attached = ++connection;
                observation = new IntersectionObserver(
                    (entries) => {
                        if (
                            active !== version ||
                            attached !== connection ||
                            !owner.ready() ||
                            toValue(options.root) !== root ||
                            toValue(options.sentinel) !== sentinel ||
                            !root.contains(sentinel)
                        )
                            return;
                        const entry = entries.find((value) => value.target === sentinel);
                        if (!entry) return;
                        if (!entry.isIntersecting) {
                            visible = false;
                            left = true;
                            return;
                        }
                        if (episodeOffset === null) episodeOffset = offset(root);
                        else if (left && offset(root) !== episodeOffset) {
                            credits = 2;
                            episodeOffset = offset(root);
                        }
                        left = false;
                        visible = true;
                        void fill(root, sentinel, active);
                    },
                    { root },
                );
                observation.observe(sentinel);
            }
            resume = () => {
                if (active !== version || !visible || !root.contains(sentinel)) return;
                visible = contains(root, sentinel);
                if (visible) void fill(root, sentinel, active);
                else left = true;
            };
            const mutations =
                typeof MutationObserver === 'undefined' ? null : new MutationObserver(connect);
            mutations?.observe(root, { childList: true, subtree: true });
            connect();
            disconnect = () => {
                connection++;
                observation?.disconnect();
                mutations?.disconnect();
            };
        },
        { flush: 'post', immediate: true },
    );
    const stopStatus = watch(
        owner.status,
        (status) => {
            if (status === 'idle') resume();
        },
        { flush: 'post' },
    );
    return () => {
        version++;
        stopStatus();
        disconnect();
        stop();
    };
}
