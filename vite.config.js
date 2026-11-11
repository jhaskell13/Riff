import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url'
import { quasar, transformAssetUrls } from '@quasar/vite-plugin'

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: 'resources/js/app.js',
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls },
        }),
        quasar({
            sassVariables: fileURLToPath(new URL('resources/css/quasar-variables.sass', import.meta.url)),
            // Under Vite 8 the AST auto-import registers its Vue
            // nodeTransform more than once, so every Quasar component gets
            // duplicate import declarations and the build fails. The regex
            // fallback dedupes imports; keep it until the plugin handles
            // Vite 8 cleanly.
            astAutoImport: false,
        }),
    ],
});
