import { defineConfig } from 'vite-plus';

export default defineConfig({
    pack: {
        entry: ['resources/js/index.ts'],
        format: ['esm'],
        platform: 'neutral',
        target: 'es2022',
        dts: true,
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
        include: ['tests/frontend/**/*.test.ts'],
        typecheck: {
            checker: 'vue-tsc',
            include: ['tests/frontend/**/*.test-d.ts'],
            enabled: true,
        },
    },
});
