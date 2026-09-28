import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
    ],

    // Chrome 109 is the oldest browser that must work: it is the last version
    // supporting the shop's Windows Server 2008 till (DEPLOYMENT_WEB.md §1).
    // Without this, esbuild emits syntax that machine cannot parse.
    build: {
        target: ['es2022', 'chrome109', 'safari16'],
    },
});
