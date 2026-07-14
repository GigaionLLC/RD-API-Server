import js from '@eslint/js';
import globals from 'globals';

// Lint the admin's vanilla jQuery assets (no build step, classic browser script).
export default [
    js.configs.recommended,
    {
        files: ['public/assets/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                jQuery: 'readonly',
                $: 'readonly',
                ApexCharts: 'readonly',
                RD: 'writable',
            },
        },
        rules: {
            'no-unused-vars': ['warn', { caughtErrors: 'none' }],
            // Intentional best-effort guards (e.g. localStorage access) use empty catches.
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
    },
    {
        files: ['scripts/**/*.mjs'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: globals.node,
        },
    },
];
