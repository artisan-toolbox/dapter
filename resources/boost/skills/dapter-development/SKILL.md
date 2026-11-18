---
name: dapter-development
description: Use when installing Dapter or building a headless Dapter listing in Laravel, Inertia, and Vue. Covers Collection and Eloquent sources, access, field and global queries, authorized actions, paging, Resources, live updates, polling, and infinite loading.
license: MIT
metadata:
  author: Allan Mariucci Carvalho
---

# Use Dapter in a Laravel application

Dapter `1.0.0-alpha.1` supports Collection and ordinary SQLite, MySQL, and PostgreSQL Eloquent offset reads. Raw protocol v2 adds field and global search, finite filters, declared All or Any grouping, bounded multi-order, and opaque order presets. The Vue adapter and composable default to protocol v1 for paging and access. Explicit protocol 2 adds these field controls, URL history, search debounce, and scoped scrolling. Native authorized actions work with either read version. Protocol 2 with `paging: 'source'` adds simple and cursor pagination, optional exact counts, and explicit cursor recovery. Optional Resources work with both read versions. Registered live replacements, polling, and guarded infinite windows require an explicit protocol 2 owner.

## Prerequisites

Use PHP ^8.5, Laravel ^13.0, and Inertia Laravel ^3.0. The frontend requires Vue ^3.5.40, Inertia Vue and Core ^3.0.3, Ziggy ^2.6.5, Node.js >=24.11.0, and Vue-aware Vite and TypeScript tooling. Inertia 3.0.3 supplies the HTTP exception callback used to classify failed reads. Vue 3.5.40 preserves the component's public props and shared slot handle identity. These instructions target alpha.1. Check the installed version with `composer show artisan-toolbox/dapter` before applying them to an existing installation.

## Install the published alpha

In the Laravel application root, install the backend and Ziggy before the frontend.

```bash
composer require artisan-toolbox/dapter:1.0.0-alpha.1 tightenco/ziggy:"^2.6.5"
npm pkg set 'dependencies.@artisan-toolbox/dapter=file:vendor/artisan-toolbox/dapter'
npm install --install-links
```

Composer supplies the JavaScript package in `vendor/artisan-toolbox/dapter`. Keep the source package outside Vite dependency pre-bundling so the exported component and composable share one module graph. Add this setting to the consuming application's existing Vite configuration:

```js
export default defineConfig({
    // Keep the application's existing plugins and other settings.
    optimizeDeps: { exclude: ['@artisan-toolbox/dapter'] },
});
```

Restart the application's Vite dev server with `--force` after adding the exclusion. Without it, a copied Composer/npm installation can produce separate adapter registries and reject a valid bound handle as unknown. This development setting does not replace the Vue Vite plugin or alter the production build. See [Vite dependency pre-bundling](https://vite.dev/guide/dep-pre-bundling).

Boost is optional development tooling and is not required at runtime.

## Analyze private framework entry points

Optional PHPStan integration requires PHPStan 2.3, directly or through Larastan, installed as development tooling in the consuming application. With `phpstan/extension-installer` installed and allowed in Composer, the next Composer install or update discovers Dapter's `extra.phpstan.includes` automatically. Without the installer, add the public configuration to the application's `phpstan.neon`. Remove the manual include if you later enable automatic discovery, because PHPStan rejects duplicate includes:

```yaml
includes:
    - vendor/artisan-toolbox/dapter/extension.neon
```

Run the application's existing PHPStan command. Private `Action`, `GlobalFilter`, and `GlobalOrder` methods no longer report `method.unused`. Ordinary unused private helpers still report that error. The extension does not validate handler signatures or replace runtime checks. PHPStan and Larastan are not runtime dependencies. See [PHPStan integration](https://atb.dev.br/docs/dapter/development#phpstan).

## Read a source

Generate a Collection source from the consuming application root:

```bash
php artisan make:datasource Products
```

Replace `app/DataSources/Products.php` with this fictional public catalog:

```php
<?php

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

Expose the named POST route `dapter.data` in the application's Ziggy configuration. Dapter calls the imported route helper directly, so a Ziggy configuration passed only to a Vue plugin is insufficient. Add `@routes` before `@vite` in the application's Inertia root Blade template, or expose a generated configuration as `globalThis.Ziggy` before mounting Vue. Keep Laravel's web middleware, sessions, and CSRF integration enabled. See [installation](https://atb.dev.br/docs/dapter/installation#read-route). Render an existing Inertia page:

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

Source identifiers use dotted ASCII PHP class segments beneath the application's actual namespace followed by `DataSources`, usually `App\DataSources`. Register optional exact source aliases with `resolve(Support\SourceRegistry::class)->register('Catalog', Products::class)`. Aliases precede convention lookup. Duplicate names and case-fold collisions fail configuration. Alias lookup is case-sensitive. There is no configurable source namespace. The HTTP endpoint constructs a fresh source even when that class is container-bound as a singleton. Its dependencies retain their own container lifetimes. Each authorized read calls `source()` once.

Apply tenant and visibility restrictions in `source()` or applicable model scopes. Keep protected reads out of constructors and options because those run before access checks. Return only fields the selected reader may receive. Current serialization respects normal model visibility and converts top-level Laravel `Arrayable` and `JsonSerializable` rows. Nested custom objects remain unsupported. A browser decoder validates received data after disclosure, so it cannot hide protected fields from the response.

Ordinary Collection offset pages support scalar rows without durable IDs. The current driver plan holds resolved options, paging, and normalized field selections. Direct use by trusted server code does not authenticate a reader or seal source, scope, connection, or projection identity. Native drivers retain their read and write PDO objects and reject replacement before catalog, count, or page queries. Builder cloning does not freeze database contents or SQL session settings. Keep the selected database and session context stable during execution. Undeclared v2 sources retain unidentified records and a null channel. Only an explicit live declaration adds aligned public IDs, optional revisions, and an authorized channel. Resource projection does not itself enable live delivery.

See [source and public record boundaries](https://atb.dev.br/docs/dapter/source-boundaries) for the working fixed-scope projection example.

## Configure paging and access

Sources may implement `Contracts\WithOptions` with public `options(): StoreOptions`. Chain `StoreOptions::make()` with `pagingMode(PagingMode::Offset)`, `pageSize(int)`, `pageSizeOptions(list<int>)`, `guard(string)`, `publicAccess(bool)`, and `authorize(string, mixed)`. Import these names from `ArtisanToolbox\Dapter` and its `Enums` namespace.

The defaults are offset mode, size 10, sizes [5, 10, 50, 100], and guard `web`. A selected size joins the allowed list. `makeWithoutDefaults()` requires an explicit guard, mode, and size. Both factories retain inherited and concrete attributes before explicit fluent overrides. Attributes do not require `WithOptions`. Their implemented equivalents include `Paging`, `ExactCounts`, `PageSize`, `PageSizeOptions`, `Guard`, `PublicAccess`, and `Authorize` under `Attributes`.

Publish configuration with `php artisan vendor:publish --tag=dapter-config`. The keys are `access.guard`, `paging.mode`, `paging.per_page`, and `paging.per_page_options`. The configured default size in capabilities is distinct from the selected size in `paging.per_page.value`.

`make:datasource Catalog.Products --eloquent` generates an Eloquent scaffold. Implement its model query before use. Ordinary SQLite, MySQL, and PostgreSQL model queries are supported. Install the matching PDO extension and configure the Laravel connection before returning the query. Windows, joins, grouping, expressions, projections, and other unverified forms fail instead of silently changing the source.

Declare the complete query before returning it. Deferred `beforeQuery` callbacks are unsupported and fail configuration before listing SQL. An `afterQuery` callback may project visible attributes while preserving the page's records, order, and identity. A callback that changes the expected row count returns a sanitized `invalid_source` server error instead of an inconsistent success. The driver checks cardinality; source authors remain responsible for membership and identity. Concurrent count/data changes can cause the same recoverable failure; call `reload()` to retry explicitly. Keep the source builder and retained nested builders stable during the read. A native clone does not deep-freeze external references held by trusted source code.

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

The v1 resource contains no executable field-operation examples. Raw v2 supports the field operations below. Protocol 2 with `paging: 'source'` also supports simple and cursor paging. Native write actions are delivered on their separate lifecycle. See the [protocol reference](https://atb.dev.br/docs/dapter/protocol) and [error handling guide](https://atb.dev.br/docs/dapter/protocol/errors).

## Read managed fields through raw v2

Implement `Contracts\WithOptions` on the source and import the enums from `ArtisanToolbox\Dapter\Enums`. For the fictional Products source above, declare these options:

```php
public function options(): StoreOptions
{
    return StoreOptions::make()
        ->publicAccess()
        ->searchableField('name', [MatchType::Contains, MatchType::Equals])
        ->orderableField('name')
        ->filterableField('name', 'catalog', ['Notebook', 'Pencil']);
}
```

Add the `StoreOptions` import and the `WithOptions` interface to the class. Send this JSON to the named `dapter.data` POST route from an authenticated browser session with its CSRF token, or from a public source's normal web session:

```json
{
    "version": 2,
    "state": {
        "version": 2,
        "fields": {"name": {"search": {"value": "Note", "matchType": "contains"}}},
        "global": {},
        "searchMatch": "all",
        "order": {"kind": "fields", "terms": [{"field": "name", "direction": "asc", "sortType": "native"}]},
        "paging": {"mode": "offset", "current": 1, "per_page": 10}
    }
}
```

The result contains Notebook, total 2, filtered 1, and `metadata` equal to `{"records":{"mode":"unidentified"},"channel":null}`. Requested order has kind `fields`. Effective selected order has kind `terms`. An omitted state resets to the current source defaults. An empty search string clears search. Literal text search escapes `%`, `_`, and the escape character for SQL. String Contains, Equals, StartsWith, and EndsWith ignore case and accents by default, including canonical decompositions. Input `joao` matches `Joao`, `JOAO`, `João`, `JOÃO`, and `JoÃo`. This source-owned policy covers field and fixed global String search. Requests send the original text.

A filter selection uses `fields.name.filters.catalog.value`, for example `"Notebook"`. Set `multiple: true` in the definition to accept a typed array. `EmptySelectionPolicy::Clear` removes an empty multiple selection. `Reject` returns validation 422. Filters on different IDs combine with AND. Values within one multiple filter combine with OR. Explicit null requires an allowed null option. A null-only definition does not coerce nonnull row values. Missing Collection targets fail configuration. Public initialized null properties and public get hooks returning null are present values, including nested DTO properties. A get hook may return null before its backing storage is initialized. Plain uninitialized and genuinely missing targets remain invalid. The nullable fallback does not expose private storage. A nullable lookup does not evaluate unrelated property hooks.

Literal match cases are `Contains`, `Equals`, `StartsWith`, and `EndsWith`. PostgreSQL additionally admits declared `FullText` as described below. Integer and Number searches require `Equals` and typed JSON numbers. `ValueType::String` accepts only strings. `Integer` accepts safe integral numbers. `Number` accepts finite numbers with magnitude at most 9007199254740991. `Boolean` accepts exactly true or false for filters and is unavailable for search. Homogeneous filter options infer a domain. Mixed integer and fractional options require explicit Number. String zero, numeric zero, false, an empty string, and null remain distinct.

SQL String search requires verified character or text storage. Native PostgreSQL UUID is not a text search target, while a UUID stored in a character column supports text search. Native ordering supports verified scalar and temporal columns, including UUID ordering. Native JSON and unverified custom types are unavailable for managed search, nonnull typed filtering, and ordering, and fail configuration before counts. A null-only filter needs an existing column and uses IS NULL without a nonnull domain conversion. SQLite JSON stored as text follows native text behavior. These limits do not prevent source-only reads.

SQL field order supports `SortType::Native`. Collection order also supports `Regular`, `String`, `Natural`, and `Numeric`. `Locale` additionally supports explicitly configured String Collections in offset and simple paging. SQL Locale and Collection cursor Locale remain unavailable. `SortDirection::Asc` and `Desc` are supported unless restricted by the definition. Multiple selected terms require a declared multi-order policy. Collection ties preserve input order without requiring IDs. SQL ties append the model primary key. Selected field order replaces the source's fallback order, while source filters and model scopes remain mandatory.

Every field operation accepts a trusted `target` alias. The browser sees the public field name and never receives the SQL target. All operations on one public field must resolve to the same target. Search defaults to String. Order and filters accept an optional `valueType`. SQL validates declared targets and column families before count or page reads. Collections validate every declared target and applicable row domain before filtering.

The equivalent repeatable class attributes are `SearchableField`, `OrderableField`, and `FilterableField`, with the same argument contracts. Use `withoutFieldSearch(field)`, `withoutFieldOrder(field)`, or `withoutFieldFilter(field, id)` to remove inherited declarations. Their attribute forms are `WithoutFieldSearch`, `WithoutFieldOrder`, and `WithoutFieldFilter`. Parent attributes apply before concrete attributes, then explicit fluent settings. Higher layers replace a whole entry. Duplicate or contradictory entries in one layer fail configuration.

See the [working field guides](https://atb.dev.br/docs/dapter/field-search) for executable cases. Set `protocol: 2` on the owning `DataAdapter` or `useDataAdapter` before using v2 state and field controls.

## Select literal sensitivity on the source

Call `StoreOptions::make()->strictLiteralSearch()` or declare `#[ArtisanToolbox\Dapter\Attributes\StrictLiteralSearch]` on the source class for case, accent, composition, and boundary-sensitive text. Both boolean arguments default to true. An explicit fluent `strictLiteralSearch(false)` wins over inherited attributes and configuration. The configured default is `dapter.declarations.strict_literal_search=false`. `StoreOptions::make()` captures configuration. `makeWithoutDefaults()` starts at library false and requires its ordinary access and paging declarations.

```php
return StoreOptions::make()
    ->publicAccess()
    ->strictLiteralSearch()
    ->searchableField('name', [MatchType::Contains, MatchType::Equals]);
```

With defined rows `['id' => 1, 'name' => 'João']` and `['id' => 2, 'name' => 'joao']`, String search `joao` returns only ID 2 in strict mode and both rows by default. The frontend sends no new property or prop. Strictness overrides in request objects are rejected. Numeric equality, finite filters, projections, source scope, ordering, and paging retain their contracts. FullText keeps engine parser, dictionary, and stemming behavior in either mode. This option does not make FullText case-sensitive.

Collection and SQLite use NFD normalization, Unicode mark removal, and lowercase without ASCII transliteration. Composer installs the explicit normalizer polyfill dependency, so literal search does not require PHP intl. SQLite registers a deterministic function on the actual execution PDO. Filtering, counting, and paging stay in SQL, but each folded value calls PHP and ordinary text indexes do not automatically cover the folded expression. PostgreSQL uses verified native normalization and lowercase. MySQL uses verified case and accent insensitive collation and can equate additional characters such as ø with o and ł with l. Do not promise identical Unicode equivalences across engines. Strict SQL uses explicit binary or codepoint predicates.

This changes an earlier preview's strict Collection and native SQL defaults. Opt in to strict mode wherever exact text is required. Recreate cursors and retained live contexts after a source policy change. Custom String literal drivers must explicitly declare awareness and implement the policy. Delegating execution to the public CollectionStoreDriver is one supported implementation path. See [Choose literal search sensitivity](https://atb.dev.br/docs/dapter/literal-search) and [Configure literal search defaults](https://atb.dev.br/docs/dapter/literal-search/configuration).

## Choose a source-specific comparison

Configure locale ordering and full-text search on the server with `Options\LocaleOptions`, `Options\FullTextOptions`, and `MatchType::FullText`. Requests cannot select locale or parser settings.

For a String Collection order, declare `SortType::Locale` and `options: new FieldOptions(locale: new LocaleOptions('pt-BR'))`. PHP intl and the requested available canonical locale are required. Keep another declared sort type when the source must remain usable without intl. Tertiary strength, normalization enabled, and numeric text collation disabled are fixed. Null comes first ascending and last descending. Equal values preserve input order. Composed and decomposed accents may compare equal, while literal search follows the independent source-owned sensitivity policy. Valid ICU parent fallback is allowed. Invented locales, keywords, invalid UTF-8, and Collator failures do not silently fall back. Cursor strings retain Native lexical comparison. See [Compare Collection values](https://atb.dev.br/docs/dapter/collection-comparisons).

For PostgreSQL text search, declare `MatchType::FullText` and `options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english'))` on a String field. A fixed global `SearchTarget` accepts `fullText: new FullTextOptions('pg_catalog.english')` with `matchType: MatchType::FullText`. Attributes accept the same DTOs. Captured configuration uses `fields.<field>.search.options.fullText.configuration` and `global.search.<target>.fullText.configuration`. Collection locale configuration uses `fields.<field>.order.options.locale.locale`.

Use a schema-qualified PostgreSQL text-search configuration available to the actual execution role. No extension or GIN index is required. GIN is optional performance setup. Active input is 1 to 512 UTF-8 bytes without NUL. Empty search retains the existing clear behavior. Plain-text terms use PostgreSQL parsing, stemming, stopwords, and AND semantics. A stopword-only query returns no matches. Null documents are empty. The package binds both configuration and input, filters in SQL, and exposes no rank or relevance order. Existing Native order and offset, simple, and cursor contracts remain. The cursor fingerprint includes the configuration. See [Search PostgreSQL documents](https://atb.dev.br/docs/dapter/postgresql-full-text).

Admission uses the actual effective execution PDO before handlers, counts, or rows, including transaction and sticky routing. Optional unavailable modes are omitted. A selected unavailable declared mode returns 422 `unsupported_operation`. A required unavailable default or fixed target fails configuration. Probe outages, denied catalogs, and unexpected execution failures remain failures. The optional comparisons DTO remains independent of literal awareness. A custom driver declaring String literal operations must honor the resolved source policy and explicitly declare `DriverCapabilities(literalSearchPolicy: true)`. Drivers without String literal operations retain their existing constructors and behavior.

Admitted SQL String literal declarations in either default or strict mode, and admitted PostgreSQL full-text declarations, require a connection without registered `Connection::beforeExecuting` callbacks. Laravel runs these callbacks after builder guards, so they could change the execution PDO or role after admission. Their presence fails configuration with `invalid_source` even when the declared search is unused. This includes literal targets on SQLite, MySQL, and PostgreSQL. Numeric-only declarations do not acquire the literal callback restriction.

MySQL, SQLite, and Collection full-text remain unavailable. Arbitrary regex has no enum or wire control and rejects before source creation, including a request with another valid filter. Input caps and PCRE or global engine limits alone do not establish bounded execution. Definition narrowing can remove comparisons but cannot alter retained policy metadata or widen field-specific finite nullable operands from projected rows. See [comparison capabilities](https://atb.dev.br/docs/dapter/comparison-capabilities).

## Control fields from Vue

Use the Products source and its declared field options above. Create the owner synchronously in Vue setup:

```ts
import { useDataAdapter } from '@artisan-toolbox/dapter';

const products = useDataAdapter('Products', {
    protocol: 2,
    id: 'products',
    searchDebounce: 200,
});
```

After the authorized first read, `products.fields?.name?.search?.call({ value: 'Note', matchType: 'contains' })` selects Notebook. Search, order, and named filter controls each expose `available`, `current`, `call(input, options?)`, and `clear(options?)`. An unavailable operation is null. Permission-narrowed fields and filter IDs can be absent. The selected search uses `{ value, matchType }`. An order uses `{ direction: 'asc', sortType: 'native' }`, with optional `multiSort: false`. A filter uses `{ value }`, where a multiple filter requires an array of advertised values. Explicit null, false, zero, and string zero remain distinct.

Criteria changes reset to page 1. Pass `{ page: 'preserve' }` as the action's second argument to preserve an ordinary exact-count offset page. Simple and cursor reject this hint, including with exact counts. The server still clamps a page beyond the filtered end. Empty search clears search alone. Other criteria and the selected page size remain intact. All actions read the latest requested intent. Sorting or reloading while search is debounced flushes that intent into the next read. Replaced promises resolve with `status: 'superseded'`.

`definition` and `capabilities` expose the same detached, deeply readonly `{ fields, global, paging }` definition. `metadata` exposes unidentified records and a null channel. These projections and field controls are null before an authorized v2 response and after access or protocol failure. Global search, filters, and order remain null. Existing v1 owners expose inert null projections.

Pass `decodeDefinition(definition)` independently of `decodeRow(row)`. Return a narrowed definition with validated literal field names, allowed match types, values, and directions. Dapter rejects added capabilities, changed domains, and a decoded definition that excludes the current selections. The decoder's actual return type determines control inputs. It does not grant backend permission. Rows remain unknown without `decodeRow`.

`replaceState(snapshot)` validates one complete requested-state snapshot before changing local intent or sending a request. Include version 2, fields, global, searchMatch, order, and paging. It replaces omitted selections and honors the supplied page. `resetState()` omits state from the next read and asks the server for current defaults. `reload()` retries the latest intent and preserves the declared container's scroll.

## Keep criteria in browser history

The unreleased local preview uses UUID browser state for explicit protocol 2. Set `url: false` on its creator to disable persistence and sharing. Version 1 keeps persistence disabled and `share()` returns null.

The URL contains a UUID under the source alias in snake case, such as `catalog_order_items`. An explicit owner `id` different from the source adds a `.id` suffix so two listings remain independent. Customize the source key with `StoreOptions::stateKey('catalog')`. Only requested criteria go into `localStorage`, under `dapter:state:<uuid>`. Rows, authorization, actions, and metadata are never saved.

Configure `dapter.state.ttl` with a positive integer number of minutes. The default is 240 minutes. Override a DataStore with `StoreOptions::stateTtl(30)` or `StoreOptions::stateTtl(now()->addHours(2))`. Pass a future Carbon deadline. Once reached, it disables new persistence without making the source unreadable. Mutable Carbon values are captured when the option is set. `makeWithoutDefaults()` uses 240 minutes independently of configuration. Each stored snapshot has a fixed expiration. Reload does not extend it.

The source version hashes the DataStore class declaration, its ancestors, and its traits. A class change invalidates previous states and share links. Global configuration and external dependencies are outside this version hash. An initial authorized default read discovers the current policy and defaults. A valid stored or shared state then requires a second read. Missing, expired, malformed, or mismatched state is removed from the URL and falls back to defaults. Changing back to defaults removes the current stored snapshot and UUID. Older history snapshots remain available until expiration.

Call `const link = catalog.share()` from a user action to create a portable absolute URL. It returns null before an accepted read, when URL persistence is disabled, or when the server does not negotiate browser state. It includes the accepted requested state and DataStore version as UTF-8 Base64URL with an `s1.` prefix. Opening that link checks the current source version and imports a fresh local UUID. `share()` does not navigate or copy to the clipboard. The `DataAdapter` slot and template ref expose the same function. Other known owners' browser-local references are omitted from the shared link.

Base64 is an encoding. Share links expose their criteria to recipients and browser history. Every restored request still runs server authorization. A local UUID works only where that browser has its matching storage record. If storage is unavailable or full, reads continue without publishing a dangling UUID.

Search and normalization replace history. Page, size, order, and filter changes push history. Back and Forward restore criteria. Public Inertia client visits are asynchronous. An already accepted visit may briefly publish an obsolete URL or add a history entry before a guarded correction converges. The correction does not publish obsolete rows or issue another data read. Unrelated query parameters, fragments, and Inertia page state remain intact.

Pass `scrollContainer` as an HTMLElement, ref, or getter returning an element or null. Dapter resets only that container after a winning criteria, page, or size render. Reload preserves its offset. History restores its recorded offset after rendering. A temporarily null target does not fall back to the window. Dapter does not scroll the body. See [Save catalog navigation](https://atb.dev.br/docs/dapter/vue-navigation).

## Select a trusted driver

Register driver aliases during application service-provider boot, before reads. Import `Drivers\DriverRegistry`, `Contracts\CollectionSource`, `Contracts\StoreDriver`, and `Drivers\CollectionStoreDriver`:

```php
resolve(DriverRegistry::class)->register(
    'catalog_collection',
    static fn (CollectionSource $source): StoreDriver => new CollectionStoreDriver(collect($source->source())),
);
```

Select the alias with `StoreOptions::make()->driver('catalog_collection')`, or the class attribute `#[Driver('catalog_collection')]`. A selected missing or incompatible alias fails configuration without falling back. Native source detection otherwise selects the Collection, SQLite, MySQL, or PostgreSQL driver from the actual source. Custom drivers must implement `Contracts\StoreDriver`. Offset-only capabilities do not grant field-operation support.

## Use the slot

Read `ready` before accessing accepted results. `paging` exposes `loading`, `empty`, `current`, `pages`, `total`, `filtered`, `has_previous`, `has_next`, `per_page.value`, `per_page.options`, and `per_page.setValue(value)`. For default offset owners, navigation actions are `goToFirst`, `goToPrevious`, `goToNext`, `goToLast`, and `goToPage(page)`. `reload()` retries the latest requested state.

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

Repeated source acquisition in one owner returns its existing handle. Use `useDataAdapter('Products', { id: 'review' })` and `{ id: 'active' }` for two independent listings in that owner. A source-owning component also accepts `id`. Bind a handle with `adapter` alone. All source, decoder, protocol, URL, debounce, and scroll options belong to its creator. To switch bound handles, recreate the view using a stable application key, such as `:key="selectedStoreId"`. Changing a mounted bound view's handle, switching its ownership mode, or changing a source-owning view's id detaches its slot and throws. Recreate the view with a stable application key when changing these creation options. A detached borrowed view does not cancel its owner. A detached source owner cancels its own pending request.

Use `useDataAdapter('Products', { decodeRow })` to infer row types from a decoder. Pass `useDataAdapter(products)` to acquire the same typed handle. A source-only lookup returns unknown rows, even when another component declared a decoder. Do not assert a free row generic. The creator fixes the decoder; omitted joining decoders retain it, while a different function reference or adding a decoder to an undecoded store fails.

Ref and getter sources require a stable explicit id, for example `useDataAdapter(() => props.source, { id: 'catalog' })`. Reacquisition must use the same source input and id. Source changes preserve handle identity and synchronously clear prior rows before loading new context. Commands retained from an obsolete owner context return superseded without reading the replacement source, including protocol 1 paging and page-size controls. Source-owning components also retain reactive source and decoder prop behavior.

Read properties directly or project them with `computed(() => products.data)`. Destructuring reads a snapshot. Unmounting a borrowed view leaves its owner's request running. Ending the owner scope clears rows, cancels pending work, and prevents late callbacks from reviving the handle. Create owners inside the relevant page or reader context. On tenant, principal, or authorization changes, recreate the owner or change the source context; Dapter cannot infer those application changes from an unchanged source string. Server authorization still runs for each request. There is no global source cache or authorization-context option.

## Compose reads and native writes

Use explicit protocol 2 for `global.search.call({ value: 'Notebook' })`, `global.filters.status.call({ value: 'active' })`, and `global.order.call({ id: 'newest' })`. Read nullable capabilities before rendering controls. The server fixes global search targets. `setSearchMatch` is null unless the server permits a choice. An ordinary field order replaces the requested list. `multiSort: true` appends or updates only when the declaration allows it. Limits reject without eviction. A preset owns its whole opaque order and cannot combine with additive terms. URL persistence contains read intent only.

Presets must preserve the complete filtered membership. Native SQL removes source fallback ordering before the callback, retains a declared fixed prefix first, and requires every result-identity target or the qualified model key. Invalid membership, prefix, or identity order fails before listing SQL. Collection presets retain original row objects and exact duplicate multiplicity without a second sort. Selected global handlers also preserve object contents and reject equal-valued clones. Their raw rows must support PHP serialization for membership checks. Ordinary reads without selected global handlers do not require that snapshot and can project opaque internal values through a Resource. Their author must supply a complete deterministic comparator and unique ties, because an opaque permutation cannot prove those comparator rules. Declaring result identity alone preserves default source ordering.

Declare global operations with `globalSearchable`, `globalFilterable`, and `globalOrderable`. Trusted filters and presets receive the actual Collection or Eloquent Builder and retain mandatory scopes. Finite values validate before handler construction. Enum cases, subsets, `cases()`, and enum classes preserve strict wire values. Use `HandlerOptions` with `EnumBinding(elements: true)` for multiple enum conversion. Class attributes and private nonstatic methods use the same contracts. Configuration is the lowest captured layer. `makeWithoutDefaults` skips configuration. See [composed declarations](https://atb.dev.br/docs/dapter/composed-declarations).

For joined or grouped queries, declare `QueryOptions` with a complete `ResultIdentity`. Pair qualified targets with literal `FieldOptions::output` keys. Identities must be non-null and unique across the complete result. Optional fixed order terms precede requested terms. Identity ties follow them. A custom count factory returns `CountQueries` with scoped total and filtered aggregate Builders. Arbitrary computed projections remain unsupported. See [advanced queries](https://atb.dev.br/docs/dapter/advanced-queries).

Declare actions using `Action` on inline private methods or select a dedicated class using `Actions` or `withActions`. Dedicated directly declared public nonstatic methods default to Visit and Post. Keep helpers private. Permit Http explicitly for JSON handlers. Declare `ActionAuthorize` or `actionAuthorize` with a real Gate and validate through a FormRequest. A FormRequest with explicit `authorize` but no Gate can accept direct native requests while remaining omitted from listing metadata. A rules-only FormRequest without a Gate is invalid. Use `ActionContext::record()` and `source()` rather than trusting a browser model. Record targets declare the exact top-level selector and primitive type. The selector comes from the original request body; query parameters cannot replace it. Handlers and FormRequests receive that original selector, including significant string spaces in uploads. Other form fields retain Laravel middleware normalization. Use each authorized metadata URL unchanged, including for string keys containing braces, slashes, or dot segments. The server reauthorizes every dispatch. Bounded mandatory Eloquent queries, including limits or offsets added by scopes, fail configuration before record lookup. Automatic record selection also rejects grouping, HAVING, aggregates, selectRaw/selectSub expression projections, PostgreSQL DISTINCT ON, and builder beforeQuery, afterQuery, or onClone callbacks. Plain qualified columns, aliases, joins, whole-row DISTINCT, and SQL access predicates remain supported. Use entity sources for record actions and Resources for display-only calculations. Advanced reporting reads and source-wide action context cloning retain their own contracts. Sources declaring broadcasting require their registered live name for reads and actions. Registered live sources use their context factory for action dispatch. The actor comes freshly from the selected guard; public row projection remains anonymous. Dispatch does not require producer authorization or issue a read-channel ticket. Eloquent record selectors apply after the complete scoped source predicate, including disjunctions and global scopes. Missing sources and actions retain native HTTP 404 responses.

Use `defineActions` with optional `decodeInput` and `decodeResult`, then pass `actionDefinitions` to the source owner. A borrowed or bound view retains those definitions. Call `actions.visit(id, input, options)` for native navigation and error bags. Native visit pending starts at Inertia onStart. A cancelled before-navigation event does not leave a pending write. Call `await actions.http(id, input, options)` for native standalone JSON. The package owns URLs, verbs, data placement, and protocol headers. Native options cannot replace them or enable HTTP optimistic behavior.

Bundler uses exact installed public Inertia types. NodeNext uses a compiler-generated conservative 3.0.3 baseline while upstream declarations use extensionless imports. Newer SDK-only fields such as `Page.rescuedProps` are available through Bundler and are not promised by NodeNext. Both retain consumer `InertiaConfig` augmentation and actual native callback objects.

Send a plain payload record, optionally containing File or Blob values. Raw FormData is unsupported.

A completed HTTP outcome has confirmed commit and decoded `data`. A local rejected outcome has not_started commit. Validation, cancellation, and failure have unknown commit, including a server mutation followed by HTTP 422. Never infer rollback or retry an uncertain write automatically. Reload explicitly to inspect the server state. Writes do not navigate, reset read criteria, reload rows, coalesce equal calls, or cancel reads implicitly. See [native actions](https://atb.dev.br/docs/dapter/actions) and [record targets and outcomes](https://atb.dev.br/docs/dapter/action-outcomes).

An infinite window combines row-target actions advertised by its loaded pages. A page without an eligible row target does not remove targets from earlier pages. Global action metadata and overlapping target definitions must remain compatible. Every dispatched write still rechecks current authorization.

Automatic polling can replace an infinite window without clearing event_overflow suspension on the same channel. A failed explicit reload also retains the pause. A successful explicit reload or an admitted channel or owner change can recover the subscription.

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

## Choose simple or cursor pagination

The default creator keeps offset types, including callable numbered and last-page actions. For a source-owned mode, use protocol 2 and `paging: 'source'`. Before readiness its mode is null. After readiness, narrow `paging.mode` to offset, simple, or cursor. Simple and cursor have null `goToPage` and `goToLast`. Simple exposes an ordinal current and null pages. Cursor exposes null current and pages. `counts: 'unknown'` means null total and filtered. `counts: 'exact'` exposes numbers without granting numbered navigation.

A Collection cursor source needs an explicit durable identity and server-owned scope. In `app/DataSources/Feed.php`, define this fixed public fixture:

```php
namespace App\DataSources;

use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithCursorScope;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;

final class Feed extends CollectionDataSource implements WithOptions, WithCursorScope
{
    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)
            ->pageSize(1)->pageSizeOptions([1, 2])
            ->query(new QueryOptions(new ResultIdentity([
                new IdentityColumn('id', 'id', ValueType::Integer),
            ])));
    }

    public function cursorScope(): string
    {
        return 'public-fictional-feed:permission-v1:query-v1';
    }

    public function source(): array
    {
        return [['id' => 1, 'name' => 'Notebook'], ['id' => 2, 'name' => 'Pencil']];
    }
}
```

In an existing Inertia Vue page, create `const feed = useDataAdapter('Feed', { protocol: 2, paging: 'source' })` during setup. Notebook appears first. A reader's Next button calls `feed.paging.goToNext()` and returns Pencil. Bind `<DataAdapter :adapter="feed">` or borrow with `useDataAdapter(feed)` to retain source paging types. Reacquiring by source and ID must repeat the protocol and paging options. A source-owned component uses `<DataAdapter source="Feed" :protocol="2" paging="source">`.

For protected data, `cursorScope()` encodes actual server tenant, permission revision, and query-definition revision. Never accept that value from the browser. Dapter reauthorizes and binds source, guard, principal, complete criteria, full order, and size separately. A fixed scope string is valid only for fixed policy. SQL ordinary model primary keys may supply identity. Joined results need unique declared identities and distinct extractable aliases. All full-order values must be non-null. Schema, null, and identity probes can run without exact counts. Collection cursor String comparison is lexical, while SQL follows its database collation. Collections remain materialized. Rows can insert, delete, or move across sort boundaries between requests. This is a live view, not a snapshot.

`PagingMode::Simple` and `#[Paging(PagingMode::Simple)]` select ordinal lookahead without exact totals. `StoreOptions::exactCounts()`, `#[ExactCounts]`, or captured `dapter.paging.exact_counts` enable optional exact counts for simple or cursor. The default is false. Pass false to the fluent method or attribute to disable them. Fluent settings override attributes and captured defaults. `makeWithoutDefaults()` excludes configuration defaults. Offset always remains exact. CountQueries selects guarded builders but does not enable the policy. Additional count queries can observe a different moment from rows.

Cursor tokens expire after 900 seconds. Treat them as opaque. HTTP 409 `cursor_expired` has `recovery: 'restart'` and retains matching accepted data. Show an explicit Restart button calling `feed.paging.goToFirst()`. This keeps current criteria, order, and size. No automatic restart occurs. Invalid or mismatched tokens return HTTP 422 validation with code `invalid_cursor` and clear protected rows and action metadata. First or a full reset reauthorizes. A real criterion, order, or size change resets position. Reload retains the current requested token. Page preservation and opaque order presets reject locally in simple or cursor mode without a request or intent change.

A custom driver opts into `Contracts\TraversalStoreDriver` and implements `traverse(SimpleReadPlan|CursorReadPlan): SimplePage|CursorPage` as well as the unchanged StoreDriver methods. Its DriverCapabilities must advertise the selected mode. Existing custom drivers remain offset-only without changes. Follow the [simple guide](https://atb.dev.br/docs/dapter/simple-paging), [cursor guide](https://atb.dev.br/docs/dapter/cursor-paging), [recovery guide](https://atb.dev.br/docs/dapter/cursor-recovery), and [optional counts guide](https://atb.dev.br/docs/dapter/paging-counts).

## Project rows through a Resource

Pass an instantiable per-row `JsonResource` subclass to `StoreOptions::useResource()` or `Attributes\UseResource`. Define its complete projected shape in `toArray(Request $request)` and use that request for visibility. Nested `JsonResource` and `ResourceCollection` values receive the same selected reader. Never replace the ambient request or change global wrapping.

Raw ID and revision extraction happens before projection, so the Resource can omit its ID. Metadata is separate from `decodeRow`, which receives projected data only. Explicit identifier metadata is a public disclosure. Existing actions inspect raw rows. Protocol 1 keeps its original envelope and never receives live metadata. The [Resource guide](https://atb.dev.br/docs/dapter/resources) contains fixed input, nested-reader examples, and expected results.

## Register authorized live delivery

Use the [live authorization guide](https://atb.dev.br/docs/dapter/live-authorization) before adding a producer. A protected live guard needs a configured user provider with current `retrieveById` support. Configure the application's broadcaster, authorization endpoint, cache, and encryption key. Pass the application's existing Echo instance explicitly. A queue worker must consume the selected delivery queue.

Before live registration, normalize all existing Laravel channel patterns to dot-separated fixed segments matching `[A-Za-z0-9_=-]+` or complete parameters matching `{[A-Za-z_][A-Za-z0-9_]*}`. Split regex alternatives into separate canonical registrations. Existing fixed segments gain no new 64-character cap. Any noncanonical existing pattern causes a boot configuration error before Dapter installs live callbacks. Laravel regex matching can otherwise bypass a protected channel's selected guard. Applications without live registrations retain ordinary Laravel pattern support.

1. Keep one source declaration shared between `options()` and `SourceRegistry::registerLive(alias, new LiveSourceRegistration(...))`. Register in a service provider before application boot finishes. Supply source, options, context-factory class, and a stable generation. Advance generation when mandatory semantics, policy, or projection changes.
2. Declare `broadcastUpdatable(identifierField, channel, revisions, identity)` or its atomic `BroadcastUpdatable` attribute. `PrivateChannel(pattern, context, authorize)` requires one complete `{context}` segment. A null channel needs the resolver captured from `dapter.broadcasting.context`. Its default null does not enable live behavior. Class layers replace the whole declaration.
3. Implement `DeliveryContextFactory::capture(Request): ?string` with a server-derived key. In `restore(key, reader)`, reload current domain membership, create a separate Request, set the supplied fresh reader before constructing the source, and explicitly construct the declared `ChannelContextResolver`. Return all three in `RestoredSource`. Do not depend on ambient auth or tenant singletons. Return null on denial.
4. Return `ScopedAudience(scope, projection)`, `GlobalAudience(projection)`, or null from the resolver. Global audiences still have principal-specific channels. Listing, private admission, and queued execution recheck access, Gates, membership, generation, and audience. A reference never caches a permission decision. Tickets expire after 86400 seconds. An explicit fresh listing reacquires expired admission.

Public delivery additionally needs `PublicChannel(name, projection)`, public access, a registered `PublicRowProjection`, and a mandatory authenticated `producerAuthorize` Gate. Public projection receives rawRow and returns plain JSON without Models, Resources, or deferred objects. `useResource` is invalid for public live delivery. Restore an anonymous data Request. Dataset, context key, identity, and projection must be globally equivalent for guest and authenticated reads. Public reads never authorize a producer.

## Publish a committed replacement

Authorize the mutation actor with the application's policy. Obtain a server-only reference through `DataStores::recipient(source, request)`, then bind it through `forRecipient(reference, connection: mutationConnection)`. Persist the application mutation and call `DataStore::update(identified: idOrList)` inside that named transaction. No mutation persistence or subscriber enumeration is automatic. The producer waits for the owning root commit and emits nothing after rollback.

Pass a safe integer, a nonempty UTF-8 string of at most 1024 bytes, or a dense list with at most 100 inputs. Typed duplicates deduplicate. `[]` does nothing. The worker rereads mandatory scope without transient listing criteria. Unknown or scope-exited rows emit no deletion signal. Custom live drivers implement `IdentityLookupDriver`. Native reverse keys use `RowIdentityProvider` and `SqlIdentitySelector`. Optional `RowRevisionProvider` values are canonical uint64 decimal strings, never timestamps or JavaScript numbers.

`dapter.broadcasting.queue_connection` and `queue` default to null and follow application defaults. Route them to the worker that owns delivery. A queued job restores the current recipient and checks policy before source access or projection. An older delivery retains its original reference expiry and cannot shorten admission renewed by a fresh listing. It contains no prepared protected row. See [committed replacements](https://atb.dev.br/docs/dapter/live-updates) and [revision races](https://atb.dev.br/docs/dapter/revision-races).

## Own Echo and polling in Vue

Use the existing factory `useDataAdapter('Products', { id: 'products-live', protocol: 2, echo: appEcho, polling: { interval: 15000 } })` after defining `appEcho` in the application's broadcaster integration. Polling also works alone without Echo. Equivalent creator props exist on `DataAdapter`; bound views borrow the handle and cannot override integrations.

Replacements affect only matching typed IDs already loaded. They keep index, counts, position, URL, scroll, and action metadata. They never insert, delete, reorder, reconcile, or reload. Equal or older revisions do nothing. Without revisions, arrival order gives no database freshness guarantee. HTTP and immediate or buffered events use the same row decoder. An invalid event retains accepted rows and exposes `integrationError` separately from HTTP errors. The pending buffer and total reentrant replay within one render settlement are each bounded by 128 events and 1 MiB. Buffer overflow suspends that owner's listener until explicit successful reload or context reset.

Polling defaults to false. Its exact interval object needs a finite value above zero and at most 2147483647 milliseconds. One timer waits for request and render settlement, skips pending or debounced work, and stops while hidden, offline, failed, or disposed. Resume schedules a future interval without catch-up. A successful explicit retry resumes it. `restartPolling()` schedules a future interval without immediate HTTP.

Call `resetContext()` synchronously when login, logout, impersonation, tenant, or permission context changes. It immediately clears protected rows, actions, listeners, pending work, and only its own saved requested snapshot, then reads fresh source defaults. No server can erase bytes already rendered in an uncooperative browser. Use [HTTP polling](https://atb.dev.br/docs/dapter/polling) for failure and restart behavior.

## Append an infinite window

Use an explicit protocol 2 owner. Set `infinite: true` for manual forward accumulation. Offset remains the default paging mode. Add `paging: 'source'` when the source declares simple or cursor paging.

```js
const catalog = useDataAdapter('Products', {
    protocol: 2,
    infinite: true,
});
const loadMore = () => catalog.infinite?.append();
```

Connect `loadMore` to your reader's Load more control after the first page settles. Calling append during setup or another read returns superseded. The first read returns one page. Append follows the accepted next ordinal or opaque token and keeps every row occurrence. Key joined rows by their complete result tuple or a stable occurrence key. Do not deduplicate by a repeated model ID.

For observation, pass `infinite: { root, sentinel }` with a bounded scrolling element and a contained trailing sentinel. Both accept elements, Vue refs, or getters. Y is the default axis. Set `axis: 'x'` for horizontal cards. Pass `scrollContainer: root` separately when URL scroll restoration uses that element. Null or replaced elements suspend observation until both resolve. Without IntersectionObserver, manual append remains available.

`handle.infinite` is null when disabled. Enabled controls expose `status`, `append()`, and `retry()`. Status is `idle`, `loading`, `paused`, or `exhausted`. Append promises include HTTP acceptance, URL publication, Vue rendering, and buffered live replay. Concurrent append calls share one operation. An append during unrelated work returns superseded without queuing.

Observation permits two automatic appends per continuous visibility episode. Leaving and reentering replenish credits only when the chosen-axis root scroll offset changes. DOM growth, element replacement, and manual append do not replenish them. A failure pauses observation. Explicit `retry()` attempts the uncommitted boundary once. Expired or invalid cursors and failures after page commit restart first. Keep accepted rows visible for a same-context failed append and show the public error.

Search, order, and size changes clear the window. Page-preserve hints fail locally. Ordinary navigation starts a new one-page window. Reload and polling restart first with the latest criteria and size. URL state identifies the start, not the accumulated tail or exact visible item. Append preserves neighboring URL parameters, the fragment, and both scroll axes. Live replacements do not add, remove, or reorder membership and do not refresh action metadata. Infinite traversal does not provide snapshot isolation.

See [Append records vertically](https://atb.dev.br/docs/dapter/infinite-loading), [Append cards horizontally](https://atb.dev.br/docs/dapter/horizontal-infinite-loading), and [Recover and refresh infinite windows](https://atb.dev.br/docs/dapter/infinite-recovery).

## Keep research separate from supported APIs

The unreleased local preview supports exact PostgreSQL L2 vector filtering. Provision pgvector and a matching `vector(N)` column before declaring the field. Use `searchableField('embedding', [MatchType::Vector], options: new FieldOptions(vector: new VectorOptions(dimensions: 3, maxDistance: 1.0)))`. Import `Options\VectorOptions` and `Options\FieldOptions`. Submit `catalog.fields.embedding.search.call({ value: [0, 0, 0], matchType: 'vector' })` after the source advertises the capability. Clear with `search.clear()`, not an empty string.

Vectors are dense arrays of exactly the configured 1 through 16,000 numeric coordinates. Every coordinate must be finite with magnitude at most 1,000,000. Dapter converts them to float32 and echoes the canonical values. The nonnegative distance threshold is inclusive. Null embeddings do not match. Existing source scopes, ordering, counts, and offset, simple, or cursor pagination still apply. Results retain the declared order rather than sorting by distance. Global search, Collection, SQLite, and MySQL do not support vector operations. Dapter does not create embeddings or call AI providers. See [PostgreSQL vector search](https://atb.dev.br/docs/dapter/vector-search).

Snapshot PagingMode controls remain unavailable. Current paging modes are offset, simple, and live cursor. Historical research seeks ordered by distance are separate from the supported filter-only API.

Persisted-key and frozen-output SQL experiments reproduce nine mutation scenarios, including unsafe primary-key reuse. They do not supply production authorization handles, generation identity, complete query and serializer checks, retention, quotas, cleanup, or a safe captured-field audience. A high-water identity bound does not freeze membership or values. Prefer the existing live paging APIs unless an application independently owns a complete stronger contract. See [Research findings](https://atb.dev.br/docs/dapter/research).
