---
name: dapter-development
description: Install the local Dapter preview, declare Collection or ordinary SQLite Eloquent sources, configure paging and access, inspect the current JSON read protocol, or share a listing through the public headless Vue DataAdapter and useDataAdapter composable in Laravel 13 and Inertia 3. Do not use for generic Eloquent queries or planned search, filtering, sorting, and live updates.
license: MIT
metadata:
  author: Allan Mariucci Carvalho
---

# Use the Dapter preview

Dapter's unpublished local preview supports Collection and ordinary SQLite Eloquent offset listing, paging and access options, PHP attributes, and a typed headless Vue adapter with owner-scoped shared handles. Search, filters, selectable sorting, MySQL, PostgreSQL, URL persistence, scrolling, Resources, broadcasts, and polling remain unavailable. Do not invent those APIs.

## Prerequisites

Use PHP ^8.5, Laravel ^13.0, and Inertia Laravel ^3.0. The frontend requires Vue 3.5, Inertia Vue and Core 3, Ziggy ^2.6.5, Node.js >=24.11.0, and Vue-aware Vite and TypeScript tooling. There is no stable release.

## Install a local preview

Place the Dapter checkout on its `1.x` branch beside the consuming Laravel application. From the application root, run Composer before npm:

```bash
composer config repositories.dapter path ../dapter
composer require artisan-toolbox/dapter:"1.x-dev@dev"
npm pkg set 'dependencies.@artisan-toolbox/dapter=file:vendor/artisan-toolbox/dapter'
npm install --install-links
```

The npm dependency resolves from `vendor/artisan-toolbox/dapter`, not the npm registry. Keep the source package outside Vite dependency pre-bundling so the exported component and composable share one module graph. Add this setting to the consuming application's existing Vite configuration:

```js
export default defineConfig({
    // Keep the application's existing plugins and other settings.
    optimizeDeps: { exclude: ['@artisan-toolbox/dapter'] },
});
```

Restart the application's Vite dev server with `--force` after adding the exclusion. Without it, a copied Composer/npm installation can produce separate adapter registries and reject a valid bound handle as unknown. This development setting does not replace the Vue Vite plugin or alter the production build. See [Vite dependency pre-bundling](https://vite.dev/guide/dep-pre-bundling).

Boost is optional development tooling and is not required at runtime.

## Read a source

Generate a Collection source from the consuming application root:

```bash
php artisan make:datasource Products
```

Replace `app/DataSources/Products.php` with this fictional public catalog:

```php
namespace App\DataSources;

use ArtisanToolbox\Dapter\Attributes\PublicAccess;
use ArtisanToolbox\Dapter\CollectionDataSource;

#[PublicAccess]
final class Products extends CollectionDataSource
{
    /** @return list<array{name: string}> */
    public function source(): array
    {
        return [['name' => 'Notebook'], ['name' => 'Pencil']];
    }
}
```

Expose the named POST route `dapter.data` in the application's Ziggy configuration. Keep Laravel's web middleware, sessions, and CSRF integration enabled. Render an existing Inertia page:

```vue
<script setup>
import { DataAdapter } from '@artisan-toolbox/dapter';
</script>

<template>
    <DataAdapter source="Products" v-slot="adapter">
        <p v-if="adapter.paging.loading">Loading...</p>
        <p v-if="adapter.error" role="alert">{{ adapter.error.message }}</p>
        <pre v-if="adapter.ready">{{ adapter.data }}</pre>
    </DataAdapter>
</template>
```

After the asynchronous load, the slot contains Notebook and Pencil, with total 2 and page 1. Sources require authentication under the configured `web` guard unless explicitly public. Public access never bypasses a declared Gate check. Use a public source only for data that guests may read.

## Keep reads inside the source boundary

Source identifiers use dotted ASCII PHP class segments beneath the application's actual namespace followed by `DataSources`, usually `App\DataSources`. There is no current alias registry or configurable source namespace. The HTTP endpoint constructs a fresh source even when that class is container-bound as a singleton. Its dependencies retain their own container lifetimes. Each authorized read calls `source()` once.

Apply tenant and visibility restrictions in `source()` or applicable model scopes. Keep protected reads out of constructors and options because those run before access checks. Return only fields the selected reader may receive. Current serialization respects normal model visibility and converts top-level Laravel `Arrayable` and `JsonSerializable` rows. Nested custom objects remain unsupported. A browser decoder validates received data after disclosure, so it cannot hide protected fields from the response.

Ordinary Collection offset pages support scalar rows without durable IDs. The current driver plan holds options and paging. Direct use by trusted server code does not authenticate a reader or seal source, scope, connection, or projection identity. Builder cloning preserves query clauses but promises neither a database snapshot nor a pinned physical connection. Resources, separate row metadata, and stronger future execution binding are unavailable.

See [source and public record boundaries](https://atb.dev.br/docs/dapter/source-boundaries) for the working fixed-scope projection example.

## Configure paging and access

Sources may implement `Contracts\WithOptions` with public `options(): StoreOptions`. Chain `StoreOptions::make()` with `pagingMode(PagingMode::Offset)`, `pageSize(int)`, `pageSizeOptions(list<int>)`, `guard(string)`, `publicAccess(bool)`, and `authorize(string, mixed)`. Import these names from `ArtisanToolbox\Dapter` and its `Enums` namespace.

The defaults are offset mode, size 10, sizes [5, 10, 50, 100], and guard `web`. A selected size joins the allowed list. `makeWithoutDefaults()` requires an explicit guard, mode, and size. Both factories retain inherited and concrete attributes before explicit fluent overrides. Attributes do not require `WithOptions`. Their implemented equivalents are `Paging`, `PageSize`, `PageSizeOptions`, `Guard`, `PublicAccess`, and `Authorize` under `Attributes`.

Publish configuration with `php artisan vendor:publish --tag=dapter-config`. The keys are `access.guard`, `paging.mode`, `paging.per_page`, and `paging.per_page_options`. The configured default size in capabilities is distinct from the selected size in `paging.per_page.value`.

`make:datasource Catalog.Products --eloquent` generates an Eloquent scaffold. Implement its model query before use. Only ordinary SQLite model queries are supported in this slice. Windows, joins, grouping, expressions, projections, and other unverified forms fail instead of silently changing the source.

The server authorizes each read before validating source-specific choices or calling `source()`. It accepts offset paging only when the actual source supports the declaration. An undeclared size returns validation 422 without reading the source. An unsupported Eloquent shape or dialect returns `server` with code `invalid_source` and status 500 before listing SQL. Denied or invalid reads return no capabilities. Capability defaults retain the declared size even when the reader selects another allowed size. The response acknowledges a page beyond the end with the last available page in both `requested.paging` and `state.paging`.

Package error identifiers are available as `Enums\ErrorCode`. JSON `error.code` and `DapterException::$errorCode` remain strings. PHP callers may pass an enum case or a trusted custom string to the exception's existing `errorCode` argument. See the [server authority guide](https://atb.dev.br/docs/dapter/authority) for working allowed, invalid, and denied requests.

## Inspect the current read protocol

The optional `resources/contracts/read-v1.json` resource contains executable examples for the current version 1 offset protocol. It ships with Composer and has one frontend import:

```js
import examples from '@artisan-toolbox/dapter/contracts/read-v1.json';

console.info(examples.fixture.rows.length);
```

The example reports 3 fictional records. `fixture` declares the rows and source paging defaults. `requests` contains original `request_json` bytes, context, expected HTTP status, complete response, and read count. `successes` and `failures` contain valid responses. `invalid_successes` and `invalid_failures` are rejection controls. These examples are optional reference data, not a parser, schema engine, or runtime dependency. Internal parsers remain private.

In PHP, `Enums\ReadProtocolVersion::V1` identifies the supported wire version. Both envelopes and requested state use integer version 1. Send JSON objects for empty `fields` and `global`, rather than lists. Omit `state` to reset to current source defaults. Requests reject decimal integer tokens such as `1.0` and integers outside the interoperable safe range. Record payloads preserve finite decimals, objects, lists, null, false, zero, and strings.

Failures have exactly `version` and `error`, plus optional `errors`. The error contains a known `kind` and nonempty opaque `code` and `message` strings. When present, `errors` contains only `_dapter_kind`, a one-element list matching `error.kind`. HTTP status does not uniquely determine the kind. Trusted custom exceptions may use `server` at 422 or `access` at 503. The Inertia 422 callback projects the reserved marker instead of exposing the complete envelope. Unknown projected markers fail closed. A malformed failure envelope clears accepted rows as a protocol error.

Search, filtering, selectable order, simple and cursor paging, requested-state restoration APIs, and write actions remain planned. The current resource contains no executable examples for those APIs. See the [protocol reference](https://atb.dev.br/docs/dapter/protocol) and [error handling guide](https://atb.dev.br/docs/dapter/protocol/errors).

## Use the slot

Read `ready` before accessing accepted results. `paging` exposes `loading`, `empty`, `current`, `pages`, `total`, `filtered`, `has_previous`, `has_next`, `per_page.value`, `per_page.options`, and `per_page.setValue(value)`. Navigation actions are `goToFirst`, `goToPrevious`, `goToNext`, `goToLast`, and `goToPage(page)`. `reload()` retries the latest requested state.

Actions resolve success, failure, or supersession. Failed refreshes retain authorized rows; access loss or an invalid protocol response clears them. Each adapter owns its pending request. Source changes discard old context. The first load starts on mount; mount examples after the reader's Start action when embedding active documentation.

Rows remain `unknown` without the optional `decodeRow(value: unknown)` prop. Its return type determines the readonly slot row type. The package validates raw wire data before invoking the decoder. Decoded arrays and plain objects are structurally readonly; application value objects retain their native methods and behavior.

Use the [working listing guide](https://atb.dev.br/docs/dapter/listing) for complete options, attributes, access, and Front Awesome presentation examples. Dapter's headless runtime does not require Front Awesome.

## Share one listing with controls

Call `useDataAdapter` synchronously in `<script setup>` or the Options API's `setup()`. Use the source declared above:

```vue
<script setup>
import { DataAdapter, useDataAdapter } from '@artisan-toolbox/dapter';

const products = useDataAdapter('Products');
</script>

<template>
    <button :disabled="products.paging.loading" @click="products.paging.reload()">
        Refresh products
    </button>
    <DataAdapter :adapter="products" v-slot="listing">
        <pre v-if="listing.ready">{{ listing.data }}</pre>
    </DataAdapter>
</template>
```

The button and slot use the same reactive readonly object and one request generation. Mount starts one initial request. The first source-only example still creates an independent store, even if its source matches a host handle. A source-owning component provides its handle to descendants calling `useDataAdapter('Products')`. Siblings share through their common host's explicit handle.

Repeated source acquisition in one owner returns its existing handle. Use `useDataAdapter('Products', { id: 'review' })` and `{ id: 'active' }` for two independent listings in that owner. A source-owning component also accepts `id`. Bind a handle with `adapter` alone; do not supply `source`, `id`, or `decodeRow` with it. To switch bound handles, recreate the view using a stable application key, such as `:key="selectedStoreId"`. Changing a mounted bound view's handle, switching its ownership mode, or changing a source-owning view's id detaches its slot and throws. Recreate the view with a stable application key when changing these creation options. A detached borrowed view does not cancel its owner. A detached source owner cancels its own pending request.

Use `useDataAdapter('Products', { decodeRow })` to infer row types from a decoder. Pass `useDataAdapter(products)` to acquire the same typed handle. A source-only lookup returns unknown rows, even when another component declared a decoder. Do not assert a free row generic. The creator fixes the decoder; omitted joining decoders retain it, while a different function reference or adding a decoder to an undecoded store fails.

Ref and getter sources require a stable explicit id, for example `useDataAdapter(() => props.source, { id: 'catalog' })`. Reacquisition must use the same source input and id. Source changes preserve handle identity and synchronously clear prior rows before loading new context. Source-owning components also retain reactive source and decoder prop behavior.

Read properties directly or project them with `computed(() => products.data)`. Destructuring reads a snapshot. Unmounting a borrowed view leaves its owner's request running. Ending the owner scope clears rows, cancels pending work, and prevents late callbacks from reviving the handle. Create owners inside the relevant page or reader context. On tenant, principal, or authorization changes, recreate the owner or change the source context; Dapter cannot infer those application changes from an unchanged source string. Server authorization still runs for each request. There is no global source cache or authorization-context option.

## Check the installed identity

```php
use ArtisanToolbox\Dapter\Dapter;

$version = Dapter::VERSION;
```

The version is currently `1.0.0-alpha.1`. The service provider registers `Dapter` as a singleton. In a Vite application:

```ts
import { version } from '@artisan-toolbox/dapter';

console.info(version);
```

Both examples report the package version. Use the default frontend entry in Vite. The optional `/dist` entry requires `npm run build` in the package checkout and supplies ESM JavaScript and declaration files for direct Node consumers.

See [installation](https://atb.dev.br/docs/dapter/installation) and the [package overview](https://atb.dev.br/packages/dapter/). No configuration or asset publication is required for this identity check.
