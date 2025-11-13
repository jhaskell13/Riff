import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls },
        }),
        quasar({
            // sassVariables: fileURLToPath(new URL('resources/css/quasar-variables.sass', import.meta.url))
        }),
    ],
});
