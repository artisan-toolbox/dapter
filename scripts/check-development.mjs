import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import vue from '@vitejs/plugin-vue';
import { createServer, loadConfigFromFile } from 'vite';

export async function checkDevelopment(consumerRoot, packageRoot) {
    const loaded = await loadConfigFromFile(
        { command: 'serve', mode: 'development' },
        join(packageRoot, 'tests/frontend/consumer/vite.config.ts'),
    );
    assert.ok(loaded, 'The public consuming application must have a Vite configuration.');
    const exclude = loaded.config.optimizeDeps?.exclude ?? [];
    await writeFile(
        join(consumerRoot, 'dev-consumer.js'),
        "import { DataAdapter, useDataAdapter } from '@artisan-toolbox/dapter';\nimport corpus from '@artisan-toolbox/dapter/contracts/read-v1.json';\nconsole.log(DataAdapter, useDataAdapter, corpus.fixture.rows);\n",
    );
    await writeFile(
        join(consumerRoot, 'index.html'),
        '<script type="module" src="/dev-consumer.js"></script>',
    );
    const server = await createServer({
        configFile: false,
        root: consumerRoot,
        cacheDir: join(consumerRoot, '.vite'),
        logLevel: 'silent',
        plugins: [vue()],
        optimizeDeps: { exclude },
        server: { host: '127.0.0.1', port: 0, hmr: false },
    });
    try {
        await server.listen();
        const base = server.resolvedUrls?.local[0];
        assert.ok(base, 'The native Vite development server must listen locally.');
        async function script(path) {
            const response = await fetch(new URL(path, base));
            const code = await response.text();
            assert.equal(response.status, 200, `The dev module ${path} must load. ${code}`);
            return code;
        }
        await script('/');
        const entry = await script('/dev-consumer.js');
        const rootImport = entry.match(/from\s*["']([^"']+)["']/)?.[1];
        assert.ok(rootImport, 'The development consumer must import the package root.');
        const contractImport = entry.match(/from\s*["']([^"']+read-v1\.json[^"']*)["']/)?.[1];
        assert.ok(
            contractImport,
            'The optional contract subpath must resolve in a source consumer.',
        );
        assert.match(await script(contractImport), /Protocol compass/);
        const rootModule = await script(rootImport);
        const componentImport = rootModule.match(
            /from\s*["']([^"']+DataAdapter\.vue[^"']*)["']/,
        )?.[1];
        const composableImport = rootModule.match(
            /from\s*["']([^"']+useDataAdapter\.ts[^"']*)["']/,
        )?.[1];
        assert.ok(componentImport, 'The source entry must export its Vue component.');
        assert.ok(
            composableImport,
            'The native dev root must import the composable module instead of pre-bundling a second registry. Exclude @artisan-toolbox/dapter from optimizeDeps.',
        );
        const component = await script(componentImport);
        const internalImport = component.match(
            /from\s*["']([^"']+useDataAdapter\.ts[^"']*)["']/,
        )?.[1];
        assert.equal(
            internalImport,
            composableImport,
            'The native dev component and public composable must resolve one identical module URL.',
        );
        assert.equal(rootImport.includes('/.vite/deps/'), false);
        console.log(
            'Verified native Vite development serves one shared source module graph for the packed component and composable.',
        );
    } finally {
        await server.close();
    }
}
