# Changelog

## Unreleased

- Verify the current query defaults, exact empty-source counts, and deterministic SQLite offset boundaries. Settle proposed scalar comparison and filtered-count rules in the central documentation without changing runtime APIs.

- Remove the unused factory trait from the Eloquent test fixture and skip only its automatic insertion rule for that file. Keep tests in Rector and PHPStan while preventing the fixer from reintroducing an undefined factory generic.

- Document and verify current source freshness, single evaluation, source-owned scope, top-level public Arrayable projection, and scalar Collection paging without row identity. Add a working fixed-scope documentation example while preserving the current read protocol and driver APIs.

- Publish optional current read protocol examples at `resources/contracts/read-v1.json` and the exact frontend subpath `@artisan-toolbox/dapter/contracts/read-v1.json`. Verify the same raw requests and complete responses through Laravel and the frontend parser and controller.
- Add `ReadProtocolVersion::V1` for the existing integer wire version without changing request or response values.
- Reject failure envelopes with extra keys or malformed or contradictory optional validation markers. Treat these responses as protocol failures and clear accepted rows. Preserve recognized custom error codes, statuses, and all four error kinds.

- Bind each authorized offset read to one accepted driver capability profile before execution. Reject unsupported Eloquent query shapes and dialects before listing SQL, prepare global scopes once on a private clone, and advertise the accepted server declaration without changing bounded page acknowledgements.
- Add typed `ErrorCode` cases for existing package errors while preserving response strings, statuses, and trusted custom `DapterException` codes.

- Require excluding the source package from Vite dependency pre-bundling so development shares the component and composable module graph. Verify native development requests against an isolated packed consumer.

- Add owner-scoped `useDataAdapter` handles, explicit component binding, descendant acquisition, stable local ids, reactive sources, and decoder conflict checks. Shared views retain one reactive readonly object, request generation, and owner lifecycle.
- Keep source-owning components isolated and reject bound handle, ownership mode, and source-owning id replacement after detaching the view. Recreate a bound view with a stable application key to switch stores.

- Implement Collection and ordinary SQLite Eloquent offset listing through a strict, versioned JSON endpoint and a headless Vue `DataAdapter`.
- Add source contracts, a conventional generator, immutable `StoreOptions` factories, inherited paging and access attributes, configurable guard authentication, and optional Laravel Gate authorization.
- Add page navigation, selected-size retention, explicit reload, typed row decoding, cancellation, per-instance request generations, access-loss cleanup, and source and built Vue consumer verification.
- Require a working public-API documentation demo and behavioral verification for every delivered setting. Add functional Front Awesome examples and a discovered demo-coverage check in the central docs application.
- Record the Composer vendor-file frontend dependency before npm installation so a local Composer symlink cannot save the sibling checkout path.
- Initialize the Laravel 13 and Inertia 3 package foundation for PHP 8.5.
- Add the versioned package identity and Maintainer-generated README header.
- Add Vite Plus linting, formatting, tests, library builds, strict TypeScript checks, and generated declarations.
- Verify source and built frontend entry points in isolated npm consumers and a Vue/Inertia application.
- Verify Composer archives retain consumer skills and exclude development and private files.
- Run backend and frontend checks on Ubuntu and Windows with lowest and stable supported dependencies.
- Add package artwork and a Font Awesome duotone export.
- Use the `1.x` development branch and versioned contributor standards.

Search, filters, selectable ordering, MySQL, PostgreSQL, URL persistence, scrolling, Resources, broadcasts, and polling remain unavailable in this local preview.
