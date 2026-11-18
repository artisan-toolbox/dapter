import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';
import { build } from 'vite/pack';
import { format } from 'vite-plus/fmt';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const destination = join(root, 'resources/js/native-action-types-node.d.ts');
const exports = [
    'Errors',
    'FormDataConvertible',
    'RequestPayload',
    'UseHttpSubmitOptions',
    'VisitOptions',
];

export async function nativeActionTypes(application, check = false) {
    const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
    const minimum = metadata.peerDependencies['@inertiajs/core'].slice(1);
    const sdk = join(application, 'node_modules/@inertiajs/core');
    const installed = JSON.parse(await readFile(join(sdk, 'package.json'), 'utf8'));
    assert.equal(
        installed.version,
        minimum,
        'Generate compatibility types from the declared minimum SDK.',
    );
    const temporary = await mkdtemp(join(application, '.dapter-native-types-'));
    try {
        const entry = join(temporary, 'native.ts');
        await writeFile(entry, `export type { ${exports.join(', ')} } from '@inertiajs/core';\n`);
        await build({
            cwd: application,
            config: false,
            entry: [entry],
            outDir: join(temporary, 'output'),
            outExtensions: () => ({ dts: '.d.ts' }),
            platform: 'neutral',
            format: ['esm'],
            dts: {
                emitDtsOnly: true,
                sourcemap: false,
                compilerOptions: {
                    moduleResolution: 'Bundler',
                    module: 'ESNext',
                    target: 'ES2022',
                    skipLibCheck: true,
                },
            },
            deps: { dts: { alwaysBundle: ['@inertiajs/core'] }, onlyBundle: ['@inertiajs/core'] },
        });
        const input = await readFile(join(temporary, 'output/native.d.ts'), 'utf8');
        const source = ts.createSourceFile(destination, input, ts.ScriptTarget.Latest, true);
        let configInterfaces = 0;
        const statements = source.statements.filter((statement) => {
            if (ts.isModuleDeclaration(statement)) {
                assert.ok(
                    statement.flags & ts.NodeFlags.GlobalAugmentation,
                    'Only unrelated SDK DOM event globals may be removed.',
                );
                assert.ok(statement.body && ts.isModuleBlock(statement.body));
                assert.equal(statement.body.statements.length, 1);
                const declaration = statement.body.statements[0];
                assert.ok(ts.isInterfaceDeclaration(declaration));
                assert.equal(declaration.name.text, 'DocumentEventMap');
                return false;
            }
            if (ts.isInterfaceDeclaration(statement) && statement.name.text === 'InertiaConfig') {
                assert.equal(
                    statement.members.length,
                    0,
                    'The public InertiaConfig bridge must remain empty.',
                );
                configInterfaces++;
                return false;
            }
            return true;
        });
        assert.equal(configInterfaces, 1, 'Preserve the SDK public configuration extension point.');
        const body = ts
            .createPrinter({ removeComments: true, newLine: ts.NewLineKind.LineFeed })
            .printFile(ts.factory.updateSourceFile(source, statements));
        assert.doesNotMatch(
            body,
            /\bimport\b|declare global/,
            'Compatibility types must be self-contained.',
        );
        const license = await readFile(join(sdk, 'LICENSE'), 'utf8');
        const output = await format(
            destination,
            `// Generated from public @inertiajs/core@${minimum} types. Run npm run native-types:generate.\n/*\n${license.trim()}\n*/\nimport type { InertiaConfig } from '@inertiajs/core';\ndeclare module '@inertiajs/core' { interface InertiaConfig {} }\n${body}`,
            { tabWidth: 4, semi: true, singleQuote: true },
        );
        assert.equal(output.errors.length, 0);
        if (check)
            assert.equal(
                await readFile(destination, 'utf8'),
                output.code,
                'Regenerate the minimum native action types.',
            );
        else await writeFile(destination, output.code);
        console.log(
            `Verified generated public native action types for @inertiajs/core@${minimum}.`,
        );
    } finally {
        await rm(temporary, { recursive: true, force: true });
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const check = process.argv.includes('--check');
    const application = process.argv.slice(2).find((argument) => argument !== '--check');
    if (application) await nativeActionTypes(resolve(application), check);
    else {
        assert.ok(
            process.env.npm_execpath,
            'Run through npm run native-types:generate or native-types:check.',
        );
        await mkdir(join(root, 'build'), { recursive: true });
        const temporary = await mkdtemp(join(root, 'build/native-action-types-'));
        try {
            const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
            await writeFile(
                join(temporary, 'package.json'),
                JSON.stringify({ private: true, type: 'module' }),
            );
            const result = spawnSync(
                process.execPath,
                [
                    process.env.npm_execpath,
                    'install',
                    '--ignore-scripts',
                    '--no-audit',
                    '--no-fund',
                    `@inertiajs/core@${metadata.peerDependencies['@inertiajs/core'].slice(1)}`,
                ],
                { cwd: temporary, encoding: 'utf8' },
            );
            assert.ifError(result.error);
            assert.equal(result.status, 0, result.stdout + result.stderr);
            await nativeActionTypes(temporary, check);
        } finally {
            await rm(temporary, { recursive: true, force: true });
        }
    }
}
