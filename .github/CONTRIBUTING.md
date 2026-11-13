# Contributing

Read the [Dapter development guide](https://atb.dev.br/docs/dapter/development) and the workspace contributor standards before changing the package.

From the package checkout, install Composer dependencies before frontend dependencies:

```bash
composer install
npm ci
```

Run the complete validation before opening a pull request against `1.x`:

```bash
composer test
composer quality:check
composer distribution:check
npm run release:build
npm run standards:check
```

Keep implementation, tests, the consumer skill, the central documentation, and the changelog synchronized. Do not commit credentials or publish a release as part of ordinary development.
