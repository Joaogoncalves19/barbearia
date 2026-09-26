import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/site.css',
                'resources/css/panel.css',
                // Direcao B: so referencia historica nas paginas de prototipo.
                'resources/css/prototypes/direcao-b.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        // Sem inlining de fontes/imagens em base64: arquivos separados cacheiam melhor.
        assetsInlineLimit: 0,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
