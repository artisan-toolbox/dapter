# Changelog

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
