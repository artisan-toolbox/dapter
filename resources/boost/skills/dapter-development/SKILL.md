---
name: dapter-development
description: Install the unreleased Dapter preview in a Laravel 13 and Inertia 3 application, connect its Composer-delivered frontend entry, or check its package version. Do not use for generic Eloquent queries or unimplemented data adapter behavior.
license: MIT
metadata:
  author: Allan Mariucci Carvalho
---

# Use the Dapter preview

Dapter currently provides package identity and development foundations. Filtering, searching, sorting, pagination, Vue composables, and adapters are not implemented. Do not invent those APIs.

## Prerequisites

Use PHP ^8.5, Laravel ^13.0, and Inertia Laravel ^3.0. Frontend tooling requires Node.js >=24.11.0 and a TypeScript-capable Vite application. There is no stable release.

## Install a local preview

Place the Dapter checkout on its `1.x` branch beside the consuming Laravel application. From the application root, run Composer before npm:

```bash
composer config repositories.dapter path ../dapter
composer require artisan-toolbox/dapter:"1.x-dev@dev"
npm install --install-links ./vendor/artisan-toolbox/dapter
```

The npm dependency resolves from `vendor/artisan-toolbox/dapter`, not the npm registry. Boost is optional development tooling and is not required at runtime.

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
