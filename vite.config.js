import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // Backoffice (Inertia) + SOPHIX Field (FE-APP-02/03) + SOPHIX Care (FE-APP-04) PWAs.
            input: ['resources/js/app.js', 'resources/mobile/main.js', 'resources/care/main.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
