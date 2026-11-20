import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite-plus';

// Node tests use Vue's client renderer to mount the public component.
const rendererVue = vue();
const transformHook = rendererVue.transform;
const transform = typeof transformHook === 'function' ? transformHook : transformHook?.handler;
if (transform) {
    rendererVue.transform = function (code, id, options) {
        return transform.call(this, code, id, {
            ...options,
            moduleType: options?.moduleType ?? 'js',
            ssr: false,
        });
    };
}

export default defineConfig({
    plugins: [rendererVue],
    pack: {
        entry: ['resources/js/index.ts'],
        deps: {
            neverBundle: ['vue', '@inertiajs/vue3', 'ziggy-js'],
            dts: { neverBundle: ['#dapter-native-action-types'] },
        },
        plugins: [vue()],
        format: ['esm'],
        platform: 'neutral',
        target: 'es2022',
        dts: { vue: true },
        sourcemap: true,
        clean: true,
    },
    fmt: {
        ignorePatterns: [
            '.ai/**',
            '.agents/**',
            '.claude/**',
            '.github/**',
            'AGENTS.md',
            'CLAUDE.md',
            'CHANGELOG.md',
            'LICENSE.md',
            'README.md',
            'composer.json',
            'pint.json',
            'resources/boost/**',
            'dist/**',
        ],
        semi: true,
        singleQuote: true,
    },
    lint: {
        ignorePatterns: ['dist/**', '.ai/**', '.agents/**'],
        options: { denyWarnings: true, typeAware: true },
    },
    test: {
        maxWorkers: 4,
        include: ['tests/frontend/**/*.test.ts'],
        typecheck: {
            checker: 'vue-tsc',
            include: ['tests/frontend/**/*.test-d.ts'],
            enabled: true,
        },
    },
});
