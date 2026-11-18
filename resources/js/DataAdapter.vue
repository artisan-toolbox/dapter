<template><slot v-if="state" v-bind="state" /></template>
<script
    setup
    lang="ts"
    generic="
        P extends DataAdapterProps &
            Record<Exclude<keyof P, keyof DataAdapterProps | keyof PublicProps>, never>,
        Row = RowOf<P>,
        Definition extends AdapterDefinition = DefinitionOf<P>,
        Actions extends ActionDefinitions = ActionsOf<P>
    "
>
import type { ActionDefinitions } from './actions.js';
import type { PublicProps } from 'vue';
import {
    type DataAdapterProps,
    type ComponentOwnerProps,
    type ComponentSlot,
    type RowOf,
    type DefinitionOf,
    type ActionsOf,
    type ComponentDecoderRequirements,
} from './adapter.js';
import type { AdapterDefinition, DefinitionDecoder } from './protocol-v2.js';
import { componentAdapter } from './useDataAdapter.js';
const props = withDefaults(
    defineProps<
        /* @vue-ignore */ P &
            DataAdapterProps &
            /* @vue-ignore */ ComponentOwnerProps<P> & {
                decodeRow?: ((value: unknown) => Row) | undefined;
                decodeDefinition?: DefinitionDecoder<Definition> | undefined;
                actionDefinitions?: Actions | undefined;
            } & /* @vue-ignore */ ComponentDecoderRequirements<P, Row, Definition, Actions>
    >(),
    { url: undefined, polling: undefined, infinite: undefined },
);
defineSlots<{ default(state: ComponentSlot<P, Row, Definition, Actions>): unknown }>();
const state = componentAdapter<P, Row, Definition, Actions>(props);
</script>
