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

Dapter provides data adapter foundations for Laravel and Inertia applications. This unreleased preview contains package identity and development tooling. Filtering, searching, sorting, and pagination APIs are not implemented yet.

## Installation

For the local preview, place this checkout beside your Laravel application. From the application root, run:

```bash
composer config repositories.dapter path ../dapter
composer require artisan-toolbox/dapter:"1.x-dev@dev"
npm install --install-links ./vendor/artisan-toolbox/dapter
```

## Usage

```php
use ArtisanToolbox\Dapter\Dapter;

dump(Dapter::VERSION); // "1.0.0-alpha.1"
```

The frontend entry also exports `version` for checking the installed package identity.

## Compatibility

PHP ^8.5, Laravel ^13.0, and Inertia Laravel ^3.0. Frontend development requires Node.js >=24.11.0. The preview uses the `1.x` branch and has no stable release.

## Documentation and policies

- [Documentation](https://atb.dev.br/packages/dapter/)
- [Contributing](https://atb.dev.br/docs/dapter/development)
- [Security policy](.github/SECURITY.md)
- [Changelog](CHANGELOG.md)
- [License](LICENSE.md)
