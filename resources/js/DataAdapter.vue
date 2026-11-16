<template><slot v-if="state" v-bind="state" /></template>
<script
    setup
    lang="ts"
    generic="
        P extends DataAdapterProps &
            Record<Exclude<keyof P, keyof DataAdapterProps | keyof PublicProps>, never>
    "
>
import type { PublicProps } from 'vue';
import { type DataAdapterProps, type DataAdapterSlot } from './adapter.js';
import { componentAdapter } from './useDataAdapter.js';
const props = defineProps</* @vue-ignore */ P & DataAdapterProps>();
defineSlots<{ default(state: DataAdapterSlot<P>): unknown }>();
const state = componentAdapter<P>(props);
</script>
