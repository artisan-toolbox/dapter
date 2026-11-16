import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { checkDevelopment } from './check-development.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const temporary = await mkdtemp(join(tmpdir(), 'dapter-consumer-'));
const npm = process.env.npm_execpath;
assert.ok(npm, 'Run this check through npm run check:dist.');

function run(command, args, cwd = temporary) {
    const result = spawnSync(command, args, {
        cwd,
        encoding: 'utf8',
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    return result.stdout;
}

try {
    const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
    const built = await import(pathToFileURL(join(root, 'dist/index.js')).href);
    assert.deepEqual(Object.keys(built).sort(), ['DataAdapter', 'useDataAdapter', 'version']);
    assert.equal(built.version, metadata.version);
    assert.equal(typeof built.DataAdapter, 'object');
    assert.equal(typeof built.DataAdapter.setup, 'function');
    assert.match(
        await readFile(join(root, 'dist/index.js'), 'utf8'),
        /import\s+\{[^}]*\bHttpNetworkError\b[^}]*\}\s+from\s+['"]@inertiajs\/core['"]/,
        'The built adapter must use the external Inertia network error class.',
    );
    const packed = JSON.parse(
        run(process.execPath, [npm, 'pack', '--json', '--pack-destination', temporary], root),
    );
    const compiler = JSON.parse(
        run(
            process.execPath,
            [npm, 'pack', '--ignore-scripts', '--json', '--pack-destination', temporary],
            join(root, 'node_modules/typescript'),
        ),
    );
    const paths = packed[0].files.map((file) => file.path);
    for (const required of [
        'dist/index.js',
        'dist/index.d.ts',
        'dist/index.d.ts.map',
        'resources/js/index.ts',
        'resources/js/DataAdapter.vue',
        'resources/js/adapter.ts',
        'resources/js/useDataAdapter.ts',
        'resources/js/protocol.ts',
        'resources/contracts/read-v1.json',
    ]) {
        assert.ok(paths.includes(required), `The archive must include ${required}.`);
    }
    assert.ok(
        paths.every(
            (file) =>
                !/^(?:tests|node_modules|vendor|build|scripts|\.ai|\.agents|\.github)\//.test(
                    file,
                ) && !/(?:^|\/)\.env(?:\.|$)/.test(file),
        ),
        'The npm archive must exclude development and private files.',
    );
    await writeFile(
        join(temporary, 'package.json'),
        JSON.stringify({ private: true, type: 'module' }),
    );
    run(process.execPath, [
        npm,
        'install',
        '--offline',
        '--ignore-scripts',
        '--strict-peer-deps',
        '--no-audit',
        '--no-fund',
        join(temporary, packed[0].filename),
        join(temporary, compiler[0].filename),
    ]);
    run(process.execPath, [npm, 'ls', '--all', '--json']);
    await checkDevelopment(temporary, root);
    for (const peer of Object.keys(metadata.peerDependencies)) {
        const installed = JSON.parse(
            await readFile(join(temporary, 'node_modules', peer, 'package.json'), 'utf8'),
        );
        console.log(`Installed runtime peer ${peer}@${installed.version}.`);
    }
    const consumer = run(process.execPath, [
        '--input-type=module',
        '-e',
        `import assert from 'node:assert/strict';
import { findPackageJSON } from 'node:module';
import { realpath } from 'node:fs/promises';
import * as dapter from '@artisan-toolbox/dapter/dist';
import metadata from '@artisan-toolbox/dapter/package.json' with { type: 'json' };
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json' with { type: 'json' };
assert.equal(corpus.version, 1);
assert.equal(corpus.fixture.rows.length, 3);
assert.equal(corpus.fixture.rows[0].values.zero, 0);
const dapterCore = findPackageJSON('@inertiajs/core', import.meta.resolve('@artisan-toolbox/dapter/dist'));
const inertiaCore = findPackageJSON('@inertiajs/core', import.meta.resolve('@inertiajs/vue3'));
assert.equal(await realpath(dapterCore), await realpath(inertiaCore));
assert.deepEqual(Object.keys(dapter).sort(), ['DataAdapter', 'useDataAdapter', 'version']);
assert.equal(typeof dapter.DataAdapter.setup, 'function');
assert.equal(typeof dapter.useDataAdapter, 'function');
for (const path of ['resources/js/adapter.ts', 'resources/js/useDataAdapter.ts', 'resources/js/protocol.ts', 'resources/js/DataAdapter.vue', 'dist/index.js', 'contracts/other.json', 'resources/contracts/read-v1.json']) {
    assert.throws(() => import.meta.resolve('@artisan-toolbox/dapter/' + path), { code: 'ERR_PACKAGE_PATH_NOT_EXPORTED' });
}
assert.equal(dapter.version, metadata.version);
process.stdout.write(dapter.version);`,
    ]);
    assert.equal(consumer, metadata.version);
    console.log(
        'Verified the installed DataAdapter ESM export, shared Inertia core, and package version.',
    );
    for (const entry of ['', '/dist']) {
        await writeFile(
            join(temporary, entry ? 'built-consumer.ts' : 'source-consumer.ts'),
            `import {
    DataAdapter, useDataAdapter, version, type AsyncOutcome, type DataAdapterProps, type DataAdapterSlot,
} from '@artisan-toolbox/dapter${entry}';
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json' with { type: 'json' };
const protocolVersion: number = corpus.version;
const protocolName: string | undefined = corpus.fixture.rows[0]?.name;
void protocolVersion; void protocolName;
// @ts-expect-error Internal protocol parsers remain private.
import { parsePage } from '@artisan-toolbox/dapter${entry}';
void parsePage;
type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends
    (<T>() => T extends B ? 1 : 2) ? true : false;
type Assert<T extends true> = T;
type Product = { id: number; details: { name: string }; labels: string[] };
const decodeRow = (value: unknown): Product => {
    if (typeof value !== 'object' || value === null || !('id' in value) || typeof value.id !== 'number') {
        throw new Error('Invalid product');
    }
    return { id: value.id, details: { name: 'Product' }, labels: ['Available'] };
};
const props = { source: 'Products', decodeRow } satisfies DataAdapterProps;
const component = DataAdapter(props);
const shared = useDataAdapter('Products', { decodeRow });
const boundComponent = DataAdapter({ adapter: shared });
type BoundSlot = Parameters<NonNullable<typeof boundComponent.__ctx>['slots']['default']>[0];
type BoundInference = Assert<Equal<Extract<BoundSlot, { ready: true }>['data'][number], {
    readonly id: number; readonly details: { readonly name: string }; readonly labels: readonly string[];
}>>;
const boundInferred: BoundInference = true;
// @ts-expect-error A bound view cannot override source.
DataAdapter({ adapter: shared, source: 'Other' });
// @ts-expect-error A source-only lookup cannot assert decoded rows.
useDataAdapter<Product>('Products');
// @ts-expect-error Reactive sources require a stable id.
useDataAdapter(() => 'Products');
// @ts-expect-error Private controllers are not public exports.
import { createController } from '@artisan-toolbox/dapter${entry}';
// @ts-expect-error Internal paths are unavailable to consumers.
import { componentAdapter } from '@artisan-toolbox/dapter/resources/js/useDataAdapter.ts';
// @ts-expect-error Lifecycle controls are absent from handles.
shared.dispose();
// @ts-expect-error Transport controls are absent from handles.
shared.submit();
void boundInferred; void createController; void componentAdapter;
const sourceComponent = DataAdapter({ source: 'Products' });
type ComponentSlot = Parameters<NonNullable<typeof component.__ctx>['slots']['default']>[0];
type SourceComponentSlot = Parameters<NonNullable<typeof sourceComponent.__ctx>['slots']['default']>[0];
type InferredRow = Extract<ComponentSlot, { ready: true }>['data'][number];
type SourceInference = Assert<Equal<Extract<SourceComponentSlot, { ready: true }>['data'], readonly unknown[]>>;
type DecoderInference = Assert<Equal<InferredRow, {
    readonly id: number; readonly details: { readonly name: string }; readonly labels: readonly string[];
}>>;
function inspect(slot: DataAdapterSlot<typeof props>, sourceOnly: DataAdapterSlot<{ source: string }>) {
    if (slot.ready) {
        const name: string | undefined = slot.data[0]?.details.name;
        // @ts-expect-error Decoded rows remain deeply readonly.
        slot.data[0]!.details.name = 'Changed';
        // @ts-expect-error Decoded arrays remain readonly.
        slot.data[0]!.labels.push('Changed');
        // @ts-expect-error The decoder does not define this field.
        void slot.data[0]?.missing;
        void name;
    }
    if (sourceOnly.ready) {
        type UnknownRows = Assert<Equal<typeof sourceOnly.data, readonly unknown[]>>;
        // @ts-expect-error Source-only rows require narrowing or a decoder.
        void sourceOnly.data[0]?.id;
        const unknownRows: UnknownRows = true;
        void unknownRows;
    }
    const outcome: Promise<AsyncOutcome> = slot.paging.goToPage(2);
    // @ts-expect-error Page numbers are numeric.
    void slot.paging.goToPage('2');
    void outcome;
}
// @ts-expect-error A free row generic cannot promise decoded records.
DataAdapter<Product>({ source: 'Products' });
// @ts-expect-error A props type with a decoder requires that decoder.
DataAdapter<typeof props>({ source: 'Products' });
// @ts-expect-error Future URL props are unavailable.
DataAdapter({ source: 'Products', url: true });
// @ts-expect-error Custom endpoints are unavailable.
const unsupported: DataAdapterProps = { source: 'Products', endpoint: '/private' };
// @ts-expect-error Package versions are strings.
const invalid: number = version;
const inferred: DecoderInference = true;
const unknownInferred: SourceInference = true;
void inspect; void unsupported; void invalid; void inferred; void unknownInferred;
`,
        );
    }
    for (const [module, moduleResolution] of [
        ['ESNext', 'Bundler'],
        ['NodeNext', 'NodeNext'],
    ]) {
        await writeFile(
            join(temporary, 'tsconfig.json'),
            JSON.stringify({
                compilerOptions: {
                    noEmit: true,
                    strict: true,
                    skipLibCheck: true,
                    resolveJsonModule: true,
                    noUncheckedIndexedAccess: true,
                    exactOptionalPropertyTypes: true,
                    target: 'ES2022',
                    module,
                    moduleResolution,
                },
                include: ['*-consumer.ts'],
            }),
        );
        run(process.execPath, [
            join(root, 'node_modules/vue-tsc/bin/vue-tsc.js'),
            '--project',
            'tsconfig.json',
        ]);
        console.log(
            `Verified source and built consumer types with ${moduleResolution} resolution.`,
        );
    }
    console.log(
        'Verified the npm archive, runtime peers, DataAdapter ESM import, and source and built consumer types with Bundler and NodeNext resolution.',
    );
} finally {
    await rm(temporary, { recursive: true, force: true });
}
