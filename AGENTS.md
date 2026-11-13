# Dapter

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `artisan-toolbox/dapter`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `atb-package-development`: use for the shared package workflow, public contracts, consumer guidance, and validation.
- `atb-documentation`: use for the canonical guides, README, changelog, and consumer skill prose.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Frontend and identity

- Keep public frontend code in `resources/js` and tests in `tests/frontend`. Use strict TypeScript and derive types from the owning APIs.
- Install Composer before npm. Consumers install `@artisan-toolbox/dapter` from `file:vendor/artisan-toolbox/dapter`.
- Use `npm run check`, `npm test`, and `npm run release:build`. The release check builds the ESM library and declaration files, then verifies a packed consumer with Bundler and NodeNext resolution.
- Keep the default Vite source entry and optional built `/dist` entry synchronized. Add dependencies and framework adapters only when implemented features need them.
- Run `composer quality:check` for the configured Maintainer checks, and `npm run standards:check` for the installed contributor standards. Synchronize generated `.ai` and `.agents` copies through `.ai/sync.mjs`.
- `Dapter` implements `Versionable` and `WithReadmeHead`. Keep `Dapter::VERSION` aligned with `package.json`, and run `php vendor/bin/maintainer readme:update --no-interaction` after editing its header metadata.
- Use `1.x` for development and changelog automation. The CI matrix covers PHP 8.5, Laravel 13, Ubuntu, and Windows in lowest and stable dependency lanes.
- The current preview contains package identity and tooling. Do not describe filtering, search, sorting, pagination, or Vue composables as implemented.
- Maintain SVG artwork with `scripts/build-logos.py`. The workspace Font Awesome export registry owns the duotone conversion. Do not claim the custom icon is published before its manual kit upload.
