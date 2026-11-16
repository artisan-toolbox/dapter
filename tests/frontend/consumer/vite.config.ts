import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    base: './',
    plugins: [vue()],
    optimizeDeps: { exclude: ['@artisan-toolbox/dapter'] },
    build: {
        outDir: fileURLToPath(new URL('../../../build/frontend', import.meta.url)),
        emptyOutDir: true,
    },
});
