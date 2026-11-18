import assert from 'node:assert/strict';
import vue from '@vitejs/plugin-vue';
import { build } from 'vite';
import { spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { checkDevelopment } from './check-development.mjs';
import { nativeActionTypes } from './generate-native-action-types.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const fixture = await mkdtemp(join(tmpdir(), 'dapter-consumer-'));
const npm = process.env.npm_execpath;

try {
    assert.ok(npm, 'Run this check through npm run check:dist.');
    const application = join(fixture, 'application');
    await mkdir(application);
    const alias = join(fixture, 'TEMP~1');
    await symlink(application, alias, process.platform === 'win32' ? 'junction' : 'dir');
    const temporary = await realpath(alias);

    function run(command, args, cwd = temporary) {
        const result = spawnSync(command, args, {
            cwd,
            encoding: 'utf8',
        });
        assert.ifError(result.error);
        assert.equal(result.status, 0, result.stdout + result.stderr);
        return result.stdout;
    }

    const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
    const built = await import(pathToFileURL(join(root, 'dist/index.js')).href);
    assert.deepEqual(Object.keys(built).sort(), [
        'DataAdapter',
        'defineActions',
        'useDataAdapter',
        'version',
    ]);
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
        'resources/js/actions.ts',
        'resources/js/native-action-types.d.ts',
        'resources/js/native-action-types-node.d.ts',
        'resources/js/useDataAdapter.ts',
        'resources/js/protocol.ts',
        'resources/js/protocol-v2.ts',
        'resources/js/controller-v2.ts',
        'resources/js/requested-state.ts',
        'resources/js/browser.ts',
        'resources/js/records.ts',
        'resources/js/realtime.ts',
        'resources/js/window.ts',
        'resources/js/infinite-observer.ts',
        'resources/contracts/updates-v1.json',
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
    const lanes =
        process.env.DAPTER_CONSUMER_PEERS === 'minimum' ? ['minimum'] : ['stable', 'minimum'];
    for (const lane of lanes) {
        const minimumPeers =
            lane === 'minimum'
                ? Object.entries(metadata.peerDependencies).map(([peer, constraint]) => {
                      assert.match(
                          constraint,
                          /^\^\d+\.\d+\.\d+$/,
                          'Declare a single caret range for each runtime peer.',
                      );
                      return `${peer}@${constraint.slice(1)}`;
                  })
                : [];
        console.log(`Checking ${lane} runtime peers.`);
        run(process.execPath, [
            npm,
            'install',
            '--ignore-scripts',
            '--strict-peer-deps',
            '--no-audit',
            '--no-fund',
            join(temporary, packed[0].filename),
            join(temporary, compiler[0].filename),
            ...minimumPeers,
        ]);
        run(process.execPath, [npm, 'ls', '--all', '--json']);
        await checkDevelopment(temporary, root);
        for (const peer of Object.keys(metadata.peerDependencies)) {
            const installed = JSON.parse(
                await readFile(join(temporary, 'node_modules', peer, 'package.json'), 'utf8'),
            );
            console.log(`Installed runtime peer ${peer}@${installed.version}.`);
        }
        if (lane === 'minimum') await nativeActionTypes(temporary, true);
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
assert.deepEqual(Object.keys(dapter).sort(), ['DataAdapter', 'defineActions', 'useDataAdapter', 'version']);
assert.equal(typeof dapter.DataAdapter.setup, 'function');
assert.equal(typeof dapter.useDataAdapter, 'function');
for (const path of ['resources/js/adapter.ts', 'resources/js/useDataAdapter.ts', 'resources/js/protocol.ts', 'resources/js/DataAdapter.vue', 'dist/index.js', 'contracts/other.json', 'contracts/updates-v1.json', 'resources/contracts/updates-v1.json', 'resources/contracts/read-v1.json']) {
    assert.throws(() => import.meta.resolve('@artisan-toolbox/dapter/' + path), { code: 'ERR_PACKAGE_PATH_NOT_EXPORTED' });
}
assert.equal(dapter.version, metadata.version);
process.stdout.write(dapter.version);`,
        ]);
        assert.equal(consumer, metadata.version);
        console.log(
            'Verified the installed DataAdapter ESM export, shared Inertia core, and package version.',
        );
        for (const proof of [
            'action-runtime.mjs',
            'paging-runtime.mjs',
            'live-runtime.mjs',
            'infinite-runtime.mjs',
            'comparison-runtime.mjs',
        ]) {
            const nativeProof = await readFile(
                join(root, 'tests/frontend/consumer', proof),
                'utf8',
            );
            console.log(run(process.execPath, ['--input-type=module', '-e', nativeProof]));
        }
        for (const proof of ['live', 'infinite', 'comparison']) {
            const sourceProof = (
                await readFile(join(root, `tests/frontend/consumer/${proof}-runtime.mjs`), 'utf8')
            ).replace("from '@artisan-toolbox/dapter/dist'", "from '@artisan-toolbox/dapter'");
            const sourceEntry = join(temporary, `${proof}-source-runtime.mjs`);
            await writeFile(sourceEntry, sourceProof);
            const rendererVue = vue();
            const hook = rendererVue.transform;
            const transform = typeof hook === 'function' ? hook : hook?.handler;
            if (transform)
                rendererVue.transform = function (code, id, options) {
                    return transform.call(this, code, id, {
                        ...options,
                        moduleType: options?.moduleType ?? 'js',
                        ssr: false,
                    });
                };
            await build({
                configFile: false,
                root: temporary,
                logLevel: 'silent',
                plugins: [rendererVue],
                ssr: { noExternal: ['@artisan-toolbox/dapter'] },
                build: {
                    ssr: sourceEntry,
                    outDir: join(temporary, `${proof}-source-build`),
                    rollupOptions: {
                        external: [
                            'vue',
                            '@inertiajs/core',
                            '@inertiajs/vue3',
                            'ziggy-js',
                            'node:assert/strict',
                        ],
                        output: { entryFileNames: 'proof.mjs' },
                    },
                },
            });
            console.log(
                run(process.execPath, [join(temporary, `${proof}-source-build/proof.mjs`)]),
            );
        }
        for (const entry of ['', '/dist']) {
            const comparisonTypes = await readFile(
                join(root, 'tests/frontend/consumer/comparison-types.ts'),
                'utf8',
            );
            await writeFile(
                join(temporary, `comparison-${entry ? 'built' : 'source'}-consumer.ts`),
                comparisonTypes.replace(
                    "from '@artisan-toolbox/dapter'",
                    `from '@artisan-toolbox/dapter${entry}'`,
                ),
            );
            const vueConsumer = await readFile(
                join(root, 'tests/frontend/consumer/App.vue'),
                'utf8',
            );
            await writeFile(
                join(temporary, `${entry ? 'built' : 'source'}-consumer.vue`),
                vueConsumer
                    .replace(
                        "from '@artisan-toolbox/dapter'",
                        `from '@artisan-toolbox/dapter${entry}'`,
                    )
                    .replace(
                        "from './comparison-types.js'",
                        `from './comparison-${entry ? 'built' : 'source'}-consumer.js'`,
                    )
                    .replace(
                        "from '@artisan-toolbox/dapter/contracts/read-v1.json';",
                        "from '@artisan-toolbox/dapter/contracts/read-v1.json' with { type: 'json' };",
                    ),
            );
            await writeFile(
                join(temporary, entry ? 'built-consumer.ts' : 'source-consumer.ts'),
                `import {
    DataAdapter, useDataAdapter, defineActions, version, type AsyncOutcome, type InfiniteOptions, type InfiniteControls, type DataAdapterProps, type DataAdapterSlot, type AdapterDefinition, type ReadonlyDeep,
} from '@artisan-toolbox/dapter${entry}';
import corpus from '@artisan-toolbox/dapter/contracts/read-v1.json' with { type: 'json' };
const infiniteOptions = { root: () => null, sentinel: () => null, axis: 'x' } satisfies InfiniteOptions;
const infiniteOwner = useDataAdapter('Products', { protocol: 2, infinite: infiniteOptions });
const infiniteView = DataAdapter({ adapter: infiniteOwner });
type InfiniteSlot = Parameters<NonNullable<typeof infiniteView.__ctx>['slots']['default']>[0];
function inspectInfinite(slot: InfiniteSlot) {
    const controls: InfiniteControls | null = slot.infinite;
    const append: Promise<AsyncOutcome> | undefined = controls?.append();
    const retry: Promise<AsyncOutcome> | undefined = controls?.retry();
    void append; void retry;
    if (controls) {
        // @ts-expect-error Infinite status is immutable public state.
        controls.status = 'idle';
    }
}
void inspectInfinite;
useDataAdapter('Legacy', { infinite: false });
DataAdapter({ source: 'Legacy', infinite: false });
// @ts-expect-error Active infinite mode requires protocol 2.
useDataAdapter('Legacy', { infinite: true });
// @ts-expect-error Infinite axes are an exact finite choice.
useDataAdapter('Products', { protocol: 2, infinite: { root: null, sentinel: null, axis: 'z' } });
// @ts-expect-error A bound view cannot override infinite ownership.
DataAdapter({ adapter: infiniteOwner, infinite: true });
const actionDefinitions = defineActions({ save: { transports: ['http', 'visit'], decodeInput: (_value:unknown) => ({id:1}), decodeResult: (_value:unknown)=>({saved:true}) }, raw: {transports:['http']} });
const actionOwner = useDataAdapter('Products', {actionDefinitions});
const actionBorrower = useDataAdapter(actionOwner);
const actionView = DataAdapter({adapter:actionBorrower});
type ActionSlot = Parameters<NonNullable<typeof actionView.__ctx>['slots']['default']>[0];
function inspectActions(slot:ActionSlot) {
    void slot.actions?.http('save',{id:1},{onSuccess(data){
        // @ts-expect-error Native HTTP data requires validation before property access.
        void data.saved;
        const unknownResult:unknown=data;void unknownResult;
    }}).then(outcome=>{if(outcome.status==='completed'){const saved:boolean=outcome.data.saved;void saved;}});
    slot.actions?.visit('save',{id:1},{preserveScroll:true,onSuccess(page){
        const component:string=page.component;void component;
        // @ts-expect-error Native pages do not invent application fields.
        void page.missing;
    }});
    // @ts-expect-error Native scroll options reject arbitrary numbers.
    slot.actions?.visit('save',{id:1},{preserveScroll:1});
    // @ts-expect-error Action payload comes from its decoder.
    void slot.actions?.http('save',{id:'1'});
    // @ts-expect-error Local definitions restrict action ids.
    void slot.actions?.http('missing',{});
    // @ts-expect-error The HTTP transport has no history or scroll options.
    void slot.actions?.http('save',{id:1},{preserveScroll:true});
    // @ts-expect-error The native headers remain package-owned.
    void slot.actions?.http('save',{id:1},{headers:{}});
    // @ts-expect-error HTTP-only actions cannot navigate.
    slot.actions?.visit('raw',{});
}
// @ts-expect-error Bound views cannot replace action definitions.
DataAdapter({adapter:actionOwner,actionDefinitions});
void inspectActions;
const live = useDataAdapter('Live', { protocol: 2, polling: { interval: 0.5 }, decodeRow: (_value: unknown) => ({ label: 'projected' }) });
void live.resetContext(); live.restartPolling();
if (live.integrationError) {const kind: 'integration'=live.integrationError.kind;void kind;}
if (live.ready) {
    const label: string | undefined=live.data[0]?.label; void label;
    // @ts-expect-error Projected accepted rows remain readonly.
    live.data[0].label='changed';
}
// @ts-expect-error Polling requires explicit protocol 2.
useDataAdapter('Live',{polling:{interval:10}});
// @ts-expect-error Bound views cannot override polling.
DataAdapter({adapter:live,polling:false});
const feed = useDataAdapter('Feed', { protocol: 2, paging: 'source' });
const borrowedFeed = useDataAdapter(feed);
const boundFeed = DataAdapter({adapter: borrowedFeed});
type FeedSlot = Parameters<NonNullable<typeof boundFeed.__ctx>['slots']['default']>[0];
function inspectFeed(slot: FeedSlot) {
    void slot.paging.goToNext();
    if(slot.paging.mode === 'offset') { void slot.paging.goToPage(2); void slot.paging.goToLast(); }
    if(slot.paging.mode === 'cursor') {
        const current: null=slot.paging.current; const pages:null=slot.paging.pages;
        const last:null=slot.paging.goToLast; void current;void pages;void last;
        if(slot.paging.counts==='exact') {const total:number=slot.paging.total;void total;}
        else {const total:null=slot.paging.total;void total;}
    }
    if(slot.paging.mode === 'simple') {const current:number=slot.paging.current;const last:null=slot.paging.goToLast;void current;void last;}
    // @ts-expect-error Advanced handles require mode narrowing before numbered navigation.
    void slot.paging.goToPage(2);
    // @ts-expect-error Advanced handles require mode narrowing before last-page navigation.
    void slot.paging.goToLast();
}
// @ts-expect-error Source paging requires protocol 2.
useDataAdapter('Feed',{paging:'source'});
// @ts-expect-error Protocol 1 remains offset only.
useDataAdapter('Feed',{protocol:1,paging:'source'});
// @ts-expect-error Component source paging requires protocol 2.
DataAdapter({source:'Feed',paging:'source'});
// @ts-expect-error Bound components cannot override their creator's paging selection.
DataAdapter({adapter:feed,paging:'offset'});
void inspectFeed;
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
const decodeDefinition = (value: ReadonlyDeep<AdapterDefinition>) => ({
    ...value,
    fields: {
        title: { search: { matchTypes: ['contains'], valueType: 'string' }, order: { directions: ['asc'], sortTypes: ['natural'] }, filters: null },
        status: { search: null, order: null, filters: { selected: { values: ['active', null], multiple: false } } },
    },
} as const satisfies AdapterDefinition);
// @ts-expect-error A free definition generic cannot invent validated operations.
DataAdapter<{ source: string }, unknown, ReturnType<typeof decodeDefinition>>({ source: 'Products' });
const v2 = useDataAdapter('Products', { protocol: 2, decodeRow, decodeDefinition });
const borrowedV2 = useDataAdapter(v2);
const boundV2 = DataAdapter({ adapter: borrowedV2 });
type V2Slot = Parameters<NonNullable<typeof boundV2.__ctx>['slots']['default']>[0];
type V2Definition = Assert<Equal<V2Slot['fields'], typeof v2.fields>>;
const inferredV2: V2Definition = true;
void inferredV2;
function inspectV2(slot: V2Slot) {
    void slot.fields?.title?.search.call({ value: 'Ada', matchType: 'contains' });
    void slot.fields?.status?.filters.selected?.call({ value: null });
    void slot.fields?.title?.order.call({ direction: 'asc', sortType: 'natural' });
    // @ts-expect-error Decoded row keys do not create operation names.
    void slot.fields?.id;
    // @ts-expect-error Permission-narrowed fields may be absent.
    void slot.fields.title.search;
    // @ts-expect-error Definition-decoded match operands remain finite.
    void slot.fields?.title?.search.call({ value: 'Ada', matchType: 'equals' });
    // @ts-expect-error Definition-decoded filter operands remain finite.
    void slot.fields?.status?.filters.selected?.call({ value: 'private' });
    // @ts-expect-error Single filters reject arrays.
    void slot.fields?.status?.filters.selected?.call({ value: ['active'] });
    // @ts-expect-error Definition-decoded order operands remain finite.
    void slot.fields?.title?.order.call({ direction: 'desc', sortType: 'natural' });
    if (slot.ready) {
        const name: string | undefined = slot.data[0]?.details.name;
        void name;
        // @ts-expect-error Independently decoded rows remain deeply readonly.
        slot.data[0]!.details.name = 'Changed';
    }
}
const definitionOnly = useDataAdapter('Products', { protocol: 2, decodeDefinition });
if (definitionOnly.ready) {
    // @ts-expect-error A definition decoder cannot infer rows.
    void definitionOnly.data[0]?.id;
}
// @ts-expect-error Bound views cannot override protocol.
DataAdapter({ adapter: v2, protocol: 2 });
// @ts-expect-error Bound views cannot override definition decoders.
DataAdapter({ adapter: v2, decodeDefinition });
void inspectV2;

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
DataAdapter({ source: 'Products', protocol: 2, url: true });
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
        await writeFile(
            join(temporary, 'augmentation-consumer.ts'),
            `
import { useDataAdapter as sourceOwner } from '@artisan-toolbox/dapter';
import { useDataAdapter as builtOwner } from '@artisan-toolbox/dapter/dist';
declare module '@inertiajs/core' {
    interface InertiaConfig {
        errorValueType: string[];
        sharedPageProps: { auth: { id: number } };
        flashDataType: { notice: string };
    }
}
for (const owner of [sourceOwner, builtOwner]) {
    const handle = owner('Products');
    handle.actions?.visit('save', {}, {
        onSuccess(page) {
            const id: number = page.props.auth.id;
            const notice: string = page.flash.notice;
            void id; void notice;
            // @ts-expect-error The public Inertia configuration controls shared props.
            const invalid: string = page.props.auth.id;
            void invalid;
        },
    });
    void handle.actions?.http('save', {}, {
        onError(errors) {
            const messages: string[] | undefined = errors.name;
            void messages;
            // @ts-expect-error The public Inertia configuration controls error values.
            const invalid: string = errors.name;
            void invalid;
        },
    });
}
`,
        );
        for (const [module, moduleResolution] of [
            ['ESNext', 'Bundler'],
            ['NodeNext', 'NodeNext'],
        ]) {
            await writeFile(
                join(temporary, 'resolution-consumer.ts'),
                `
import { useDataAdapter as sourceOwner } from '@artisan-toolbox/dapter';
import { useDataAdapter as builtOwner } from '@artisan-toolbox/dapter/dist';
for (const owner of [sourceOwner, builtOwner]) {
    owner('Products').actions?.visit('save', {}, {
        onSuccess(page) {
            ${lane === 'stable' && moduleResolution === 'Bundler' ? '' : '// @ts-expect-error Minimum native declarations do not promise newer SDK fields.'}
            const rescued: string[] = page.rescuedProps;
            void rescued;
        },
    });
}
`,
            );
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
                    vueCompilerOptions: { strictTemplates: true },
                    include: ['*-consumer.ts', '*-consumer.vue'],
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
    }
} finally {
    await rm(fixture, { recursive: true, force: true });
}
