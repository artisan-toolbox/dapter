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
        <DataAdapter
            source="Products"
            :protocol="2"
            :url="false"
            :decode-row="decodeProduct"
            :decode-definition="decodeDefinition"
            v-slot="adapter"
        >
            <p v-if="adapter.ready">{{ adapter.data[0]?.name }}</p>
            <button
                @click="adapter.fields?.name?.search.call({ value: 'Ada', matchType: 'contains' })"
            >
                Search by name
            </button>
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: 'active' })">
                Active products
            </button>
            <!-- @vue-expect-error Definition-decoded match types remain finite in actual Vue templates. -->
            <button
                @click="adapter.fields?.name?.search.call({ value: 'Ada', matchType: 'equals' })"
            >
                Invalid match
            </button>
            <p>
                <!-- @vue-expect-error Record keys do not define operations. -->
                {{ adapter.fields?.id }}
            </p>
        </DataAdapter>
        <DataAdapter :adapter="filteredProducts" v-slot="adapter">
            <p v-if="adapter.ready">{{ adapter.data[0]?.name }}</p>
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: null })">
                Unassigned products
            </button>
            <!-- @vue-expect-error Shared and bound views preserve finite filter operands. -->
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: 'private' })">
                Invalid filter
            </button>
        </DataAdapter>
        <DataAdapter
            source="Comparisons"
            :protocol="2"
            :url="false"
            :decode-definition="decodeComparisonDefinition"
            :decode-row="decodeResourceRow"
            v-slot="adapter"
        >
            <p v-if="adapter.ready">{{ adapter.data[0]?.record.name }}</p>
            <button
                @click="
                    adapter.fields?.name?.search.call({ value: 'running', matchType: 'full_text' })
                "
            >
                Search documents
            </button>
            <button
                @click="adapter.fields?.name?.order.call({ direction: 'asc', sortType: 'locale' })"
            >
                Order by locale
            </button>
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: null })">
                Select null
            </button>
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: false })">
                Select false
            </button>
            <button @click="adapter.fields?.status?.filters.selected?.clear()">
                Clear selection
            </button>
            <p>{{ adapter.fields?.name?.search.available.fullText.configuration }}</p>
            <p>{{ adapter.fields?.name?.order.available.locale.locale }}</p>
            <!-- @vue-expect-error Full-text field match modes remain finite in Vue templates. -->
            <button
                @click="
                    adapter.fields?.name?.search.call({ value: 'running', matchType: 'contains' })
                "
            >
                Invalid literal match
            </button>
            <!-- @vue-expect-error Locale field sort modes remain finite in Vue templates. -->
            <button
                @click="adapter.fields?.name?.order.call({ direction: 'asc', sortType: 'native' })"
            >
                Invalid sort
            </button>
            <!-- @vue-expect-error Field directions remain finite in Vue templates. -->
            <button
                @click="adapter.fields?.name?.order.call({ direction: 'desc', sortType: 'locale' })"
            >
                Invalid direction
            </button>
            <!-- @vue-expect-error Scalar filter values remain finite in Vue templates. -->
            <button @click="adapter.fields?.status?.filters.selected?.call({ value: 'private' })">
                Invalid scalar
            </button>
            <p v-if="adapter.ready">
                <!-- @vue-expect-error Resource projection and field operations have independent shapes. -->
                {{ adapter.data[0]?.name }}
            </p>
            <p>
                <!-- @vue-expect-error Resource keys do not grant field operations. -->
                {{ adapter.fields?.record }}
            </p>
        </DataAdapter>
        <DataAdapter :adapter="comparisonProducts" v-slot="adapter">
            <p v-if="adapter.ready">{{ adapter.data[0]?.record.name }}</p>
            <button
                @click="adapter.fields?.name?.order.call({ direction: 'asc', sortType: 'locale' })"
            >
                Shared locale order
            </button>
            <!-- @vue-expect-error Bound slots preserve exact sort-mode inference. -->
            <button
                @click="adapter.fields?.name?.order.call({ direction: 'asc', sortType: 'numeric' })"
            >
                Invalid shared sort
            </button>
        </DataAdapter>
        <DataAdapter :adapter="literalProducts" v-slot="adapter">
            <p v-if="adapter.ready">{{ adapter.data[0]?.record.name }}</p>
            <button
                @click="
                    adapter.fields?.name?.search.call({
                        value: 'JoÃo%_!',
                        matchType: 'starts_with',
                    })
                "
            >
                Literal field search
            </button>
            <button @click="adapter.global?.search.call({ value: 'João' })">
                Literal global search
            </button>
            <!-- @vue-expect-error Literal controls do not grant full-text operations. -->
            <button
                @click="
                    adapter.fields?.name?.search.call({ value: 'Joao', matchType: 'full_text' })
                "
            >
                Invalid literal mode
            </button>
            <!-- @vue-expect-error Source strictness cannot be selected by a reader. -->
            <button
                @click="adapter.global?.search.call({ value: 'Joao', strictLiteralSearch: true })"
            >
                Invalid strict override
            </button>
        </DataAdapter>
        <DataAdapter source="Feed" :protocol="2" paging="source" :url="false" v-slot="adapter">
            <button v-if="adapter.paging.mode === 'offset'" @click="adapter.paging.goToPage(2)">
                Second page
            </button>
            <p v-else-if="adapter.paging.mode === 'cursor'">
                Cursor pages {{ adapter.paging.pages }}
            </p>
            <p v-else-if="adapter.paging.mode === 'simple'">Page {{ adapter.paging.current }}</p>
            <!-- @vue-expect-error Source paging requires narrowing numbered actions. -->
            <button @click="adapter.paging.goToPage(2)">Invalid direct page</button>
        </DataAdapter>
        <DataAdapter :adapter="feed" v-slot="adapter">
            <button @click="adapter.paging.goToNext()">Next feed window</button>
            <button v-if="adapter.paging.mode === 'offset'" @click="adapter.paging.goToLast()">
                Last page
            </button>
            <!-- @vue-expect-error Bound source handles preserve nullable last actions. -->
            <button @click="adapter.paging.goToLast()">Invalid last page</button>
            <p v-if="adapter.paging.mode === 'cursor' && adapter.paging.counts === 'exact'">
                {{ adapter.paging.total.toFixed(0) }} records
            </p>
            <p v-if="adapter.ready">{{ adapter.data[0]?.name }}</p>
        </DataAdapter>
        <!-- @vue-expect-error Source paging requires protocol 2 in Vue templates. -->
        <DataAdapter source="Feed" paging="source" />
        <!-- @vue-expect-error Bound views cannot override creator paging. -->
        <DataAdapter :adapter="feed" paging="offset" />
        <DataAdapter
            source="Live"
            :protocol="2"
            :echo="echo"
            :polling="{ interval: 15000 }"
            :url="false"
            :decode-row="decodeProduct"
            v-slot="adapter"
        >
            <p v-if="adapter.ready">{{ adapter.data[0]?.name }}</p>
            <p v-if="adapter.integrationError">{{ adapter.integrationError.code }}</p>
            <button @click="adapter.resetContext()">Reset reader context</button>
            <button @click="adapter.restartPolling()">Restart polling</button>
        </DataAdapter>
        <!-- @vue-expect-error Echo requires explicit protocol 2 in real Vue templates. -->
        <DataAdapter source="Live" :echo="echo" />
        <!-- @vue-expect-error Polling requires explicit protocol 2 in real Vue templates. -->
        <DataAdapter source="Live" :polling="{ interval: 15000 }" />
        <!-- @vue-expect-error Bound views cannot override polling even when disabled. -->
        <DataAdapter :adapter="feed" :polling="false" />
        <DataAdapter :adapter="infiniteFeed" v-slot="adapter">
            <p>{{ adapter.infinite?.status }}</p>
            <button @click="adapter.infinite?.append()">Load more</button>
            <button @click="adapter.infinite?.retry()">Retry loading</button>
        </DataAdapter>
        <DataAdapter
            source="Observed"
            :protocol="2"
            :infinite="{ root: infiniteRoot, sentinel: infiniteRoot, axis: 'x' }"
            v-slot="adapter"
        >
            {{ adapter.infinite?.status }}
        </DataAdapter>
        <DataAdapter source="Legacy" :infinite="false" />
        <DataAdapter source="Legacy" :protocol="1" :infinite="false" />
        <DataAdapter source="Infinite" :protocol="2" :infinite="infiniteEnabled" />
        <!-- @vue-expect-error Infinite loading requires explicit protocol 2. -->
        <DataAdapter source="Infinite" infinite />
        <!-- @vue-expect-error A dynamic boolean requires explicit protocol 2. -->
        <DataAdapter source="Infinite" :infinite="infiniteEnabled" />
        <!-- @vue-expect-error Bound views cannot override infinite creation options. -->
        <DataAdapter :adapter="infiniteFeed" :infinite="false" />
        <Link href="/dapter-preview">Dapter preview</Link>
    </main>
</template>
<script setup lang="ts">
import {
    DataAdapter,
    useDataAdapter,
    version,
    type AdapterDefinition,
    type DapterEcho,
    type ReadonlyDeep,
} from '@artisan-toolbox/dapter';
import { shallowRef } from 'vue';
import {
    decodeComparisonDefinition,
    decodeLiteralDefinition,
    decodeResourceRow,
} from './comparison-types.js';
const literalProducts = useDataAdapter('LiteralPeople', {
    protocol: 2,
    id: 'literal-people',
    url: false,
    decodeDefinition: decodeLiteralDefinition,
    decodeRow: decodeResourceRow,
});
const comparisonProducts = useDataAdapter('Comparisons', {
    protocol: 2,
    id: 'comparison-products',
    url: false,
    decodeDefinition: decodeComparisonDefinition,
    decodeRow: decodeResourceRow,
});
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json';
import { Link } from '@inertiajs/vue3';
const channel = { listen() {}, stopListening() {}, error() {} };
const echo: DapterEcho = { private: () => channel, channel: () => channel };
const infiniteRoot = shallowRef<HTMLElement | null>(null);
const infiniteEnabled = shallowRef<boolean>(false);
const infiniteFeed = useDataAdapter('Infinite', {
    protocol: 2,
    paging: 'source',
    infinite: true,
    id: 'infinite-feed',
    url: false,
    decodeRow: decodeProduct,
});
const feed = useDataAdapter('Feed', {
    protocol: 2,
    paging: 'source',
    id: 'feed',
    url: false,
    decodeRow: decodeProduct,
});
const filteredProducts = useDataAdapter('Products', {
    id: 'filtered-products',
    protocol: 2,
    url: false,
    decodeRow: decodeProduct,
    decodeDefinition,
});
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
function decodeDefinition(value: ReadonlyDeep<AdapterDefinition>) {
    return {
        ...value,
        fields: {
            name: {
                search: { matchTypes: ['contains'], valueType: 'string' },
                order: null,
                filters: null,
            },
            status: {
                search: null,
                order: null,
                filters: { selected: { values: ['active', null], multiple: false } },
            },
        },
    } as const satisfies AdapterDefinition;
}
</script>
