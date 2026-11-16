<!-- MAINTAINER:README_HEAD:START -->
<div id="maintainer-title" align="center">
    <img src="logo/dapter-icon.svg" alt="" width="88">
    <h1>Dapter</h1>
</div>

<p id="maintainer-badges" align="center">
<a href="https://atb.dev.br/packages/dapter/"><img src="https://img.shields.io/badge/Status-Preview-FF5947?style=flat-square" alt="Preview"></a>
<a href="https://atb.dev.br/packages/dapter/#requirements"><img src="https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square" alt="PHP"></a>
<a href="https://atb.dev.br/packages/dapter/#requirements"><img src="https://img.shields.io/badge/Laravel-%5E13.0-FF2D20?style=flat-square" alt="Laravel"></a>
<a href="https://github.com/artisan-toolbox/dapter/actions/workflows/tests.yml?query=branch%3A1.x"><img src="https://img.shields.io/github/actions/workflow/status/artisan-toolbox/dapter/tests.yml?branch=1.x&amp;label=Tests&amp;style=flat-square" alt="Tests"></a>
<a href="https://atb.dev.br/packages/dapter/"><img src="https://img.shields.io/badge/Docs-Read-blue?style=flat-square" alt="Docs"></a>
</p>

<p id="maintainer-description" align="center">
    Data adapter foundations for Laravel and Inertia applications.
</p>
<!-- MAINTAINER:README_HEAD:END -->

Dapter reads and paginates server-owned data through a headless Vue adapter. This unpublished local preview supports Collections and ordinary SQLite Eloquent queries, offset paging, typed options, PHP attributes, and guard and Gate access checks.

## Installation

For the local preview, place this checkout beside your Laravel application. From the application root, run:

```bash
composer config repositories.dapter path ../dapter
composer require artisan-toolbox/dapter:"1.x-dev@dev"
npm pkg set 'dependencies.@artisan-toolbox/dapter=file:vendor/artisan-toolbox/dapter'
npm install --install-links
```

## Usage

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

Expose the package route `dapter.data` through Ziggy, keep Laravel's web session and CSRF integration, and render the source in an existing Inertia page:

```vue
<script setup>
import { DataAdapter } from '@artisan-toolbox/dapter';
</script>

<template>
    <DataAdapter source="Products" v-slot="adapter">
        <pre v-if="adapter.ready">{{ adapter.data }}</pre>
    </DataAdapter>
</template>
```

After loading, the slot contains the two records. The public attribute is explicit for this fictional catalog; authentication is required by default. Run the [functional examples](https://atb.dev.br/docs/dapter/listing) for paging, configuration, and access behavior.

## Compatibility

PHP ^8.5, Laravel ^13.0, Inertia 3, Vue 3.5, Ziggy ^2.6.5, and Node.js >=24.11.0 for frontend tooling. The source entry needs Vue-aware Vite and TypeScript tooling, plus `optimizeDeps.exclude` for `@artisan-toolbox/dapter` during development. Follow the [installation guide](https://atb.dev.br/docs/dapter/installation) for the consuming application's Vite configuration. Search, filters, selectable ordering, MySQL, PostgreSQL, URL persistence, scrolling, and live updates remain planned. The preview uses `1.x` and has no stable release.

## Documentation and policies

- [Documentation](https://atb.dev.br/packages/dapter/)
- [Contributing](https://atb.dev.br/docs/dapter/development)
- [Security policy](.github/SECURITY.md)
- [Changelog](CHANGELOG.md)
- [License](LICENSE.md)
