<!-- MAINTAINER:README_HEAD:START -->
<div id="maintainer-title" align="center">
    <img src="logo/dapter-icon.svg" alt="" width="88">
    <h1>Dapter</h1>
</div>

<p id="maintainer-badges" align="center">
<a href="https://github.com/artisan-toolbox/dapter/releases"><img src="https://img.shields.io/badge/version-1.0.0--beta.3-blue?style=flat-square" alt="version"></a>
<a href="https://packagist.org/packages/artisan-toolbox/dapter"><img src="https://img.shields.io/badge/Packagist-F28D1A?style=flat-square&amp;logo=packagist&amp;logoColor=white" alt="Packagist"></a>
<a href="https://atb.dev.br/docs/dapter#requirements"><img src="https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square" alt="PHP"></a>
<a href="https://atb.dev.br/docs/dapter#requirements"><img src="https://img.shields.io/badge/Laravel-%5E13.0-FF2D20?style=flat-square" alt="Laravel"></a>
<a href="https://github.com/artisan-toolbox/dapter/actions/workflows/tests.yml?query=branch%3A1.x"><img src="https://img.shields.io/github/actions/workflow/status/artisan-toolbox/dapter/tests.yml?branch=1.x&amp;label=Tests&amp;style=flat-square" alt="Tests"></a>
<a href="https://atb.dev.br/docs/dapter"><img src="https://img.shields.io/badge/Docs-Read-blue?style=flat-square" alt="Docs"></a>
</p>

<p id="maintainer-description" align="center">
    Headless data listings for Laravel and Vue.
</p>
<!-- MAINTAINER:README_HEAD:END -->

Dapter connects Laravel data sources to headless Vue listings. Add search, filters, sorting, pagination, and authorized actions to your own presentation. Dapter is in beta, and its public API may change before the first stable release.

## Installation

Check the [installation requirements](https://atb.dev.br/docs/dapter/installation). From the root of a Laravel application with Inertia and Vue, run Composer before npm.

```bash
composer require 'artisan-toolbox/dapter:@beta' tightenco/ziggy
npm pkg set 'dependencies.@artisan-toolbox/dapter=file:vendor/artisan-toolbox/dapter'
npm install --install-links
```

## Usage

Complete the [installation guide](https://atb.dev.br/docs/dapter/installation), then create `app/DataSources/Products.php`:

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

Expose the package route `dapter.data` through Ziggy, keep Laravel's web session and CSRF integration, and render the source in an existing Inertia page:

```vue
<script setup>
import { DataAdapter } from '@artisan-toolbox/dapter';
</script>

<template>
    <DataAdapter source="Products" v-slot="adapter">
        <p v-if="adapter.paging.loading">Loading...</p>
        <p v-if="adapter.error" role="alert">{{ adapter.error.message }}</p>
        <ul v-if="adapter.ready">
            <li v-for="(product, index) in adapter.data" :key="index">
                {{ product.name }}
            </li>
        </ul>
    </DataAdapter>
</template>
```

After loading, the list displays Notebook and Pencil. The example uses the public attribute to allow guest access. Dapter requires authentication by default. Run the [functional examples](https://atb.dev.br/docs/dapter/listing) for paging, configuration, and access behavior.

## Documentation and policies

- [Documentation](https://atb.dev.br/docs/dapter)
- [Contributing](https://atb.dev.br/docs/dapter/development)
- [Security policy](.github/SECURITY.md)
- [Changelog](CHANGELOG.md)
- [License](LICENSE.md)
