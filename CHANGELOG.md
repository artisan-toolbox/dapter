# Changelog

## [1.0.0-beta.3] - 2026-11-20

### Documentation

- **Clarify README badge, Vue UI reuse, and cross-package verification standards** (`df927a0`)
  Update the documentation and package-development skills in both .ai and .agents. Published README headers must start with a GitHub Releases version badge followed by a static badge linking to the package's Packagist page; unreleased packages use an in-development badge. Keep numeric versions in badges and compatibility ranges in installation guides, scope Composer prerelease flags to the package, and verify that installation commands resolve and Maintainer regenerates the expected header. In the Vue documentation app, prefer suitable Web Awesome Vue controls from the public exports of @artisan-toolbox/front-awesome while preserving existing domain wrappers, semantic HTML, Inertia navigation, and keyboard, focus, and accessibility behavior. Document exceptions when the public API cannot meet those requirements. After finding the cause of a test, build, or static analysis failure or a critical runtime defect, check every Artisan Toolbox package, including packages that have not adopted the standards. Compare resolved dependencies and tools, code patterns, and CI lanes; run the smallest relevant check; and record each outcome or blocker. Fix and verify confirmed issues in scope and report confirmed out-of-scope issues as pending. Refresh the standards manifest and lockfile to 1.3.0 without changing the schema or skill list. No runtime or API changes.

### Continuous Integration

- **Streamline CI and verify PHP type coverage across all source files** (`ceb33ec`)
  Use the Artisan Toolbox Maintainer 1.14.2 action in both CI workflows, disable checkout credential persistence, and set timeouts of 20 minutes for the main test job and 10 minutes for native backend verification. The main workflow supports manual runs and retains Ubuntu and Windows coverage with PHP 8.5, Node.js 24, and both lowest and stable Composer dependency resolution. It now uses the project's declared constraints instead of injecting Laravel 13 and Testbench 11 requirements, validates the Git archive before installing dependencies, and checks project standards before npm installation. Testbench preparation remains explicit. Separate steps make PHP analysis, formatting, type coverage, Pest tests, frontend checks and tests, library and consumer builds, and npm distribution verification easier to diagnose. Native backend CI retains its PHP extensions and memory limit but no longer pins PHP and Composer versions explicitly. Raise the Composer requirement for Maintainer to ^1.14.3 and make composer test:types fail if Pest falls below 100% type coverage or its report omits any PHP file under src/. Isolate configuration publishing and caching for parallel Pest workers; set MAINTAINER_PEST_PARALLEL=false to retain serial execution. The npm release build no longer runs the standalone frontend type-only suite, which remains available separately. Distribution verification sets Inertia's form success duration to zero in the affected test processes without changing shipped defaults. Maintainer also gains per-task AI model and locale settings and uses tests.yml as its release workflow.

### Maintenance

- **Update README badges and let Composer select a compatible beta** (`4b3cc1b`)
  Replace the Preview badge in the maintained README header with a version badge linked to GitHub Releases and a Packagist badge linked to the Dapter package. Update the Composer example to use `composer require 'artisan-toolbox/dapter:@beta' tightenco/ziggy` instead of pinning Dapter to a specific beta and specifying an explicit Ziggy constraint. The package-scoped flag lets Composer select a compatible Dapter prerelease without broadening stability for the whole project. Keep the npm commands unchanged and direct readers to the installation guide for dependency requirements, Vite setup, and protocol guidance rather than duplicating them in the README. Clarify that authentication is required by default and that the example's public attribute permits guest access. Note that the public API may change before the first stable release. No runtime changes.

## Unreleased

- Test PHP 8.5 and 8.6 in CI with the Maintainer 1.14.4 setup action.

- Run required PHP and frontend checks through Maintainer 1.14.3. Use four workers for Pest on Unix and for Vitest. Run Pest serially on native Windows runners.

- Isolate configuration publishing and caching in the Pest suite so parallel workers can boot reliably.
- Use the Maintainer 1.14.2 root action in both CI workflows. Validate the Git archive before installing dependencies in the compatibility matrix.
- Use a beta stability flag in the README installation command so Composer can select a compatible Dapter release.
- Verify that PHP type coverage includes every source file and streamline release and CI checks while retaining the PHP and frontend compatibility matrix.

## [1.0.0-beta.2] - 2026-11-18

### Features

- **Synchronize Dapter npm version with release build** (`65ba2b4`)
  Bumped the Dapter frontend/package version constant `Dapter::VERSION` from `1.0.0-beta.1` to `1.0.0-beta.2`, and extended the `Dapter` class to participate in the versioning lifecycle by implementing `BeforeVersioning` (in addition to existing `Versionable` and `WithReadmeHead`). Added a `beforeVersioning(string $current, string $next): void` hook that, from the project root, executes `npm version $next --no-git-tag-version --allow-same-version --ignore-scripts` and then `npm run release:build`. This ensures the frontend/package version is synchronized with the release version and that the frontend is rebuilt/validated as part of the versioning workflow. User impact: releases will now trigger these npm side effects automatically during versioning; compatibility/API behavior is unchanged aside from the new pre-versioning execution and the version number bump. Migration: none required for library consumers; maintainers should be aware that versioning will execute npm commands and may fail if the npm steps fail.

### Maintenance

- **Align README and Boost skill with beta.1 documentation** (`2caf809`)
  Updated project documentation to align the README and Boost skill references with the `beta.1` published behavior/version. Specifically, the README was updated to reflect the release version change from `1.0.0-alpha.1` to `1.0.0-beta.1`, including the published release badge/text and the Composer install command while leaving other install constraints (e.g., the Ziggy constraint `tightenco/ziggy:"^2.6.5"`) intact. Additionally, documentation under `resources/boost/skills/dapter-development/SKILL.md` was updated from `1.0.0-alpha.1` to `1.0.0-beta.1`, including the stated current version (`Dapter::VERSION`) and wording around local preview vs. `Dapter beta.1`. User impact: no runtime/functional behavior changes—only documentation consistency with the published `beta.1` release. Migration: none required for users; maintainers should ensure docs match the intended published release line.

## [1.0.0-beta.1] - 2026-11-18

### Features

- **Add vector L2 filtering and browser state snapshots** (`6340357`)
  Includes the changes introduced by commit 6340357: feat: add vector L2 filtering and browser state snapshots.

## Unreleased

- Align the README and Boost skill with published beta.1, including vector filtering and portable browser state.
- Synchronize the `@artisan-toolbox/dapter/dist` version export and npm lockfile with `1.0.0-beta.1`.
- Synchronize the npm version and rebuild and validate the frontend before Maintainer creates a release commit.

### Features

- Added exact PostgreSQL pgvector L2 field filtering with prepared float32 vectors, inclusive distance thresholds, capability admission, scoped counts, and existing paging modes. Embedding generation and distance ranking remain application concerns.
- Replaced protocol 2 JSON URL snapshots with expiring localStorage snapshots referenced by UUID. Configure `state.ttl` in minutes, or set `stateTtl()` with minutes or a future Carbon deadline and `stateKey()` with a query key.
- Added class, ancestor, and trait version checks for restored states and portable `share()` links. Missing, expired, or incompatible state returns to authorized defaults. A cold restored listing reads current policy before it reapplies the saved criteria.


### Documentation

- Updated the README and Laravel Boost skill to install the published `1.0.0-alpha.1` release from Composer.

## [1.0.0-alpha.1] - 2026-11-18

### Fixes

- **Normalize simple-page rows while preserving Eloquent keys** (`0f435b0`)
  Updates the Eloquent mapping for “simple-page” rows to be normalized in a consistent shape while preserving the original Eloquent keys. This prevents subtle identity issues where downstream code could lose or mismatch record identity after transforming/rehydrating page rows. User impact: fixes incorrect/unstable page data ordering or linkage when rendering or processing simple-page results. Compatibility: should be backward compatible at the API level; it changes internal data normalization and key preservation semantics to be correct. Migration: none required.

- **Preserve non-string name validation and analysis in console** (`9871fd5`)
  Fixes the console validation/analysis behavior to preserve non-string “name” values rather than implicitly converting/dropping them. This prevents validation from changing the meaning of user input or losing diagnostic context during console processing. User impact: more accurate error reporting and validation behavior when name fields are not strings. Compatibility: likely behavior change for edge cases only; it improves correctness without affecting standard string inputs. Migration: none required.

- **Reject deferred callbacks and sanitize page bounds in Eloquent** (`f804cfd`)
  Modifies the Eloquent/page logic to reject deferred callbacks and to sanitize page bounds. The goal is to prevent delayed execution paths that can lead to inconsistent results and to ensure pagination/bounds parameters stay within safe/expected limits. User impact: eliminates incorrect pagination outcomes and potential runtime issues caused by deferred execution or invalid bounds. Compatibility: behavior changes for invalid/deferred scenarios; standard usage should be unaffected. Migration: none for normal users; if callers relied on deferred behavior or supplied out-of-range bounds, they must correct inputs.

- **Canonicalize packed consumer root for Vite** (`3618599`)
  Includes the changes introduced by commit 3618599: fix(distribution): canonicalize packed consumer root for Vite.

### Tests

- **Add query defaults and regression coverage** (`028a51f`)
  Adds regression tests covering query default values and counts. This increases confidence that query-building logic produces stable defaults and that result counts remain correct across changes. User impact: improved quality via earlier detection of regressions; no runtime behavior change expected. Compatibility: none. Migration: none.

### Build

- **Initial commit** (`f8bdf69`)
  Establishes the initial repository contents, including baseline project structure and starting implementation state. User impact: establishes the project for the first time. Compatibility: n/a. Migration: n/a.

### Continuous Integration

- **Add native backend DB workflow and distribution checks** (`2d3a63f`)
  Introduces a CI workflow that uses a native backend database setup and adds distribution verification checks as part of the build/test pipeline. This improves reliability by ensuring the project is validated against the expected DB/runtime environment and that distribution artifacts or packaging outputs meet defined expectations. User impact: more trustworthy CI results; fewer surprises from environment/package differences. Compatibility: no public runtime/API changes. Migration: none required.

### Maintenance

- **Bump Inertia and Vue minimum versions for adapter** (`583484a`)
  Raises the minimum supported versions of Inertia and Vue required by the adapter. This ensures the adapter targets maintained features/compatibility levels and can rely on newer runtime capabilities. User impact: projects pinned below the new minima may fail to install or run. Compatibility: potential breaking change for older setups (dependency constraint tightening). Migration: update project dependencies to meet the new Inertia/Vue minimum versions before upgrading.

- **Add Dapter preview docs, contracts, and new adapter code** (`7f193a0`)
  Introduces/updates Dapter preview documentation, related contracts, and adds new adapter implementation code. This expands or refines the adapter preview surface area and documentation for upcoming/experimental behavior. User impact: developers can follow preview documentation and use updated adapter contracts/code; however, preview features may evolve and are not necessarily stable. Compatibility: likely affects adapter internals/preview APIs; check version/preview expectations. Migration: follow the new preview docs/contract expectations if using preview features.

- **Add AI standards bundle skills and documentation** (`28c10df`)
  Adds a documentation bundle for AI standards and introduces related “skills” documentation. This primarily formalizes how agents should follow standards in the repository (process/documentation and agent guidance). User impact: none at runtime; it improves contribution/agent consistency. Compatibility: no public API/behavior changes. Migration: none.

## Unreleased

- Preserve the Eloquent model bound through query preparation and cloning for static analysis with the minimum supported dependencies. Normalize simple-page rows to sequential keys when Eloquent collections use string keys.

- Set a bounded 512M memory limit in both PHPUnit configurations so the complete Pest suites and Maintainer checks work with PHP's 128M default. Exercise that starting limit in CI.

- Reject record-action query shapes that can change represented identities when a selector is applied. Preserve ordinary columns, qualified aliases, joins, whole-row DISTINCT, and source-wide context cloning.

- Preserve original body selectors through native uploads and FormRequests while retaining ordinary Laravel form cleanup, guard and Gate responses, and host-route isolation.

- Include built JavaScript, declarations, and source maps in Git-backed releases. Verify declared frontend targets before CI builds and compare canonical rebuilt artifacts with the release tree.
- Preserve arbitrary valid record selectors in authorized action URLs, reject bounded mandatory SQL record sources before lookup, require registered broadcasting contexts for native actions, and preserve renewed private admission when older deliveries resume.

- Restore registered live sources through their context factory for native actions, using the fresh selected-guard actor without issuing channel tickets. Group complete Eloquent predicates and scopes before record selectors. Preserve nullable object fields without reading unrelated PHP property hooks, allow opaque Resource inputs when no global transform is selected, and reject cloned or mutated Collection rows from selected handlers and presets.
- Preserve page-local targeted actions across infinite windows, keep overflowed live channels suspended during automatic polling, supersede obsolete protocol 1 controls, and count native action visits only after dispatch starts. Strengthen negative type assertions against unrelated diagnostics.

- Reject SQL String literal declarations on connections with registered beforeExecuting callbacks, including strict and unused declared searches, because callbacks can change execution after admission. Numeric-only declarations retain their existing prerequisites.

- Change the unpublished preview String literal search default to case and accent insensitive matching, including canonical decompositions. Add source-owned strictLiteralSearch(), StrictLiteralSearch attributes, captured declarations.strict_literal_search configuration, and explicit literalSearchPolicy awareness for custom drivers. Keep frontend wire shapes and FullText parser semantics unchanged. Require explicit strict declarations for earlier exact-text behavior and restart cursor or retained live contexts after a policy change. Document native pgvector and persisted-pagination research as deferred public features with no new vector or snapshot runtime API.

- Add explicit ICU Collection locale ordering and PostgreSQL plain-text full-text filtering with bound schema-qualified configuration, effective-PDO admission, exact capability metadata, scoped native paging, and early unsupported-operation rejection. Preserve literal comparisons, custom drivers, native cursor order, and finite nullable controls. Keep arbitrary regex and other full-text engines unavailable.

- Add opt-in protocol 2 infinite windows with lossless forward accumulation, complete-settlement append serialization, explicit retry after failure, bounded vertical and horizontal observation, first-page refresh, and URL restoration of the window start. Preserve ordinary paging defaults and joined-row occurrences. Document the public controls with executable examples.

- Add optional per-row Resources, stable context-bound private and explicit public live registrations, committed scoped updates, aligned typed identity and uint64 revisions, owned protocol 2 Echo replacements, bounded request-race replay, explicit context reset, and independent settlement-aware polling. Preserve default protocol 1 and all prior paging and action behavior.

- Add opt-in protocol 2 simple and cursor pagination with bounded lookahead, durable identity, authenticated scope-bound 900-second cursors, explicit restart recovery, optional exact counts, and compatible custom traversal drivers. Preserve default offset contracts and add focused public documentation demos.

- Ship an optional PHPStan 2.3 extension for private Action, GlobalFilter, and GlobalOrder entry points through the public extension.neon configuration and Composer extension-installer discovery. Keep ordinary unused private-method errors and all runtime dependencies unchanged.
- Use exact installed public Inertia action types under Bundler and a reproducible conservative 3.0.3 baseline under NodeNext. Preserve consumer InertiaConfig augmentation and actual native callback objects in both modes.
- Require opaque order presets to preserve the complete filtered membership, declared fixed prefix, and unique identity order. Remove native source fallback ordering before preset callbacks and reject invalid native results before listing SQL. Preserve original Collection row objects and duplicate multiplicity without a second sort.

- Retain native missing-source action errors, explicit enum binding for compatible union parameters, and mandatory native query scopes and identity before and after global scope application.

- Add inline, inherited private, trait, and dedicated native actions with finite verbs and transports, source and record Gate policies, validated FormRequests, strict record selectors, and per-record metadata URLs. Preserve native visits, redirects, error bags, and HTTP JSON behavior.
- Add typed same-handle action definitions and independent input and result decoders. Report confirmed completion, local rejection, and unknown commit after validation, cancellation, or transport failure without automatic retry or listing refresh.
- Add composed global search, finite enum-aware handler filters, opaque order presets, bounded multiple order terms, fixed prefixes, result identity ties, and declared search grouping in opt-in protocol 2. Keep default protocol 1 reads compatible and expose authorized action metadata on both versions.
- Add configuration-seeded declarations, immutable nested options, equivalent attributes and private handlers, explicit removals, native source and engine requirements, qualified joined projections, grouped counts, and scoped custom CountQueries.

- Add explicit Vue protocol v2 owners with immutable server capability projections, independent row and definition decoders, field search, named filters, and one field order. Keep protocol v1 as the default.
- Add atomic requested-state replacement, explicit default reset, page-preserving criteria actions, latest-intent patches, search debounce, coalescing, settled supersession, and source-epoch guards for captured controls.
- Add source-bound, versioned URL namespaces through public Inertia history APIs. Restore saved criteria, normalize acknowledged pages, preserve neighboring parameters, and scope scroll resets and history restoration to the declared container. Already accepted Inertia client visits can publish a transient obsolete URL; guarded corrections converge after settling without changing rows or issuing another data read.

- Add opt-in raw read protocol v2 for declared field search, typed single-value and multiple-value filters, and one selected field order. Preserve the complete v1 paging wire shape and keep the default Vue adapter on v1.
- Add immutable field declarations, repeatable attribute equivalents, inherited whole-entry replacement, and explicit removal methods and attributes. Reject duplicate declarations, target conflicts, invalid domains, and unsupported driver modes.
- Support ordinary SQLite, MySQL, and PostgreSQL Eloquent sources through explicit native drivers. Preserve scoped source predicates, literal SQL search, deterministic primary-key ties, and separate total and filtered counts. Validate accepted query shapes, actual connection and grammar pairs, declared columns, and column families before listing reads.
- Add trusted source and driver alias registries, explicit driver selection and its attribute, exact source lookup, and case-fold collision rejection. Missing or incompatible selected drivers fail without fallback.
- Add v2 unidentified-record metadata with a null channel by default. Registered live sources provide aligned durable identity and optional revisions through the Resource and live-update preview described above.
- Declare the Illuminate components used directly at runtime as production dependencies.
- Reject invalid nonnull choices for null-only filters with HTTP 422. Validate native SQL operation support before counts, and reject replacement read or write PDO handles before catalog or listing queries.

- Resolve the packed consumer's real filesystem path before starting Vite so Windows temporary-directory aliases do not trigger its short-path restriction. Exercise a directory alias and verify the Vite root in the distribution check.

- Read raw console input before validating the data source name so current Larastan and PHPStan analysis preserves rejection of non-string programmatic arguments. Register the provider explicitly in Testbench so command analysis also works with cold discovery manifests, and give Composer analysis the same 2G memory limit as Maintainer. Keep missing-name and dotted-name validation unchanged.

- Require Inertia Vue and Core 3.0.3 or later within 3.x for the adapter's HTTP exception callback, and Vue 3.5.40 or later within 3.x for public props and shared slot handle identity. Exercise all minimum peers in the lowest dependency lane. Allow packed-consumer checks to resolve peers from the npm registry with an empty cache, and verify published configuration contents without platform-specific path comparisons.

- Reject deferred Eloquent query callbacks before listing SQL and fail with a sanitized configuration error when result callbacks change the expected offset page size. Reject invalid native counts before reading page rows. Preserve callbacks that only project visible attributes.

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
