import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const temporary = await mkdtemp(join(tmpdir(), 'dapter-consumer-'));
const npm = process.env.npm_execpath;
assert.ok(npm, 'Run this check through npm run check:dist.');

function run(command, args, cwd = temporary) {
    const result = spawnSync(command, args, {
        cwd,
        encoding: 'utf8',
        env: { ...process.env, npm_config_cache: join(temporary, 'npm-cache') },
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    return result.stdout;
}

try {
    const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
    const built = await import(pathToFileURL(join(root, 'dist/index.js')).href);
    assert.equal(built.version, metadata.version);
    const packed = JSON.parse(
        run(process.execPath, [npm, 'pack', '--json', '--pack-destination', temporary], root),
    );
    const paths = packed[0].files.map((file) => file.path);
    for (const required of ['dist/index.js', 'dist/index.d.ts', 'resources/js/index.ts']) {
        assert.ok(paths.includes(required), `The archive must include ${required}.`);
    }
    assert.ok(
        paths.every((file) => !file.startsWith('tests/') && !file.startsWith('node_modules/')),
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
        '--no-audit',
        '--no-fund',
        join(temporary, packed[0].filename),
    ]);
    await writeFile(
        join(temporary, 'consumer.ts'),
        `import { version } from '@artisan-toolbox/dapter';\n` +
            `import { version as builtVersion } from '@artisan-toolbox/dapter/dist';\n` +
            `const versions: string[] = [version, builtVersion];\n` +
            `// @ts-expect-error Package versions are strings.\n` +
            `const invalid: number = version;\nvoid versions;\nvoid invalid;\n`,
    );
    for (const [module, moduleResolution] of [
        ['ESNext', 'Bundler'],
        ['NodeNext', 'NodeNext'],
    ]) {
        run(process.execPath, [
            join(root, 'node_modules/typescript/bin/tsc'),
            '--noEmit',
            '--strict',
            '--skipLibCheck',
            '--resolveJsonModule',
            '--target',
            'ES2022',
            '--module',
            module,
            '--moduleResolution',
            moduleResolution,
            'consumer.ts',
        ]);
    }
    const consumer = run(process.execPath, [
        '--input-type=module',
        '-e',
        "import { version } from '@artisan-toolbox/dapter/dist'; process.stdout.write(version);",
    ]);
    assert.equal(consumer, metadata.version);
    console.log('Verified the npm archive, ESM import, and Bundler and NodeNext consumer types.');
} finally {
    await rm(temporary, { recursive: true, force: true });
}
