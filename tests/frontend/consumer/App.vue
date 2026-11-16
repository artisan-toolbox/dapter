<template>
    <main>
        <h1>Dapter {{ version }}</h1>
        <p>Protocol examples {{ corpus.fixture.rows.length }}</p>
        <DataAdapter :key="version" source="Products" :decode-row="decodeProduct" v-slot="adapter">
            <p v-if="!adapter.ready">{{ adapter.status }}</p>
            <ul v-else>
                <li v-for="product in adapter.data" :key="product.id">{{ product.name }}</li>
            </ul>
            <button @click="adapter.paging.goToNext()">Next page</button>
        </DataAdapter>
        <DataAdapter source="Products" v-slot="adapter">
            <p v-if="adapter.ready">
                <!-- @vue-expect-error Source-only slot records remain unknown. -->
                {{ adapter.data[0]?.name }}
            </p>
        </DataAdapter>
        <DataAdapter :adapter="products" v-slot="adapter">
            <p v-if="adapter.ready">{{ adapter.data[0]?.name }}</p>
            <button @click="products.paging.reload()">Refresh shared products</button>
        </DataAdapter>
        <Link href="/dapter-preview">Dapter preview</Link>
    </main>
</template>
<script setup lang="ts">
import { DataAdapter, useDataAdapter, version } from '@artisan-toolbox/dapter';
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json';
import { Link } from '@inertiajs/vue3';
const products = useDataAdapter('Products', { id: 'shared-products', decodeRow: decodeProduct });
function decodeProduct(value: unknown): { id: number; name: string } {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('id' in value) ||
        typeof value.id !== 'number' ||
        !('name' in value) ||
        typeof value.name !== 'string'
    )
        throw new Error('Invalid product');
    return { id: value.id, name: value.name };
}
</script>
