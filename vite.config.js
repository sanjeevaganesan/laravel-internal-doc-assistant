import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * Vite configuration for the React + Vercel AI SDK frontend.
 *
 * Key additions vs the Blade+Alpine branch (main):
 *   1. @vitejs/plugin-react — enables JSX transpilation, React Fast Refresh
 *      (hot module replacement that preserves component state during dev)
 *   2. Entry point changed to app.jsx (JSX extension tells Vite to apply
 *      the React plugin's JSX transform to this file and its imports)
 *
 * How Vite + Laravel work together:
 *   - 'npm run dev'   → dev server on :5173, hot reload via HMR
 *   - 'npm run build' → compiles JS/CSS to public/build/ with content-hash filenames
 *   - @vite(['...']) in Blade → injects <script> and <link> tags pointing to
 *     the right URL (dev server in dev, public/build/ in prod)
 */
export default defineConfig({
    plugins: [
        laravel({
            // Entry points: CSS for Tailwind, JSX for the React app
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),

        /*
         * @vitejs/plugin-react enables:
         *   1. JSX transform (converts <Component /> → React.createElement(...)
         *      using the new JSX runtime — no 'import React' needed in every file)
         *   2. React Fast Refresh — preserves component state during hot reload,
         *      so you don't lose the conversation while editing the component
         *   3. React DevTools support in the browser
         */
        react(),

        // Tailwind CSS v4 via Vite plugin (scans all files for utility classes)
        tailwindcss(),
    ],

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
