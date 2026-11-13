<template>
    <main>
        <h1>Dapter {{ version }}</h1>
        <p>This Vue consumer imports the public package entry.</p>
        <Link href="/dapter-preview">Dapter preview</Link>
    </main>
</template>

<script setup lang="ts">
import { version } from '@artisan-toolbox/dapter';
import { Link } from '@inertiajs/vue3';
</script>
