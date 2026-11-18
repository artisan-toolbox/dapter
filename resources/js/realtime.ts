import type { ChannelDescriptor } from './records.js';
export type DapterEchoChannel = {
    listen(event: string, callback: (value: unknown) => void): unknown;
    stopListening(event: string, callback: (value: unknown) => void): unknown;
    error(callback: (error: unknown) => void): unknown;
};
export type DapterEcho = {
    private(name: string): DapterEchoChannel;
    channel(name: string): DapterEchoChannel;
};
export type PollingOptions = false | { readonly interval: number };
export type IntegrationError = {
    readonly kind: 'integration';
    readonly code:
        | 'echo_missing'
        | 'subscription_failed'
        | 'invalid_event'
        | 'decoder_failed'
        | 'event_overflow'
        | 'render_failed';
    readonly message: string;
};
const bridges = new WeakMap<DapterEchoChannel, Set<(error: unknown) => void>>();
export function subscribe(
    echo: DapterEcho,
    descriptor: ChannelDescriptor,
    update: (value: unknown) => void,
    failed: (error: unknown) => void,
): () => void {
    const channel =
        descriptor.type === 'private'
            ? echo.private(descriptor.name)
            : echo.channel(descriptor.name);
    let sinks = bridges.get(channel);
    if (!sinks) {
        sinks = new Set();
        bridges.set(channel, sinks);
        const owners = sinks;
        try {
            channel.error((error) => {
                [...owners].forEach((sink) => sink(error));
            });
        } catch (cause) {
            bridges.delete(channel);
            throw cause;
        }
    }
    const owners = sinks;
    owners.add(failed);
    try {
        channel.listen(descriptor.event, update);
    } catch (cause) {
        owners.delete(failed);
        channel.stopListening(descriptor.event, update);
        throw cause;
    }
    let active = true;
    return () => {
        if (!active) return;
        active = false;
        owners.delete(failed);
        channel.stopListening(descriptor.event, update);
    };
}
