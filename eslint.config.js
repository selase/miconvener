/**
 * ESLint 10 reads only flat config. The repo had a legacy setup aimed at Vue,
 * which the frontend no longer uses, so `npm run lint` failed before linting
 * anything.
 *
 * This lints the React (JSX) and plain JS sources for mistakes that are bugs
 * rather than style: unreachable code, duplicate keys, self-assignment,
 * impossible comparisons and the like. It uses only rules built into ESLint,
 * so it needs no new dependencies.
 *
 * `no-unused-vars` is deliberately absent: without eslint-plugin-react, ESLint
 * cannot see that a component is used in JSX and would report every import.
 */
export default [
    {
        ignores: [
            'vendor/**',
            'public/**',
            'node_modules/**',
            'storage/**',
            'bootstrap/cache/**',
            'dist/**',
            '.worktrees/**',
        ],
    },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
        },
        rules: {
            'constructor-super': 'error',
            'for-direction': 'error',
            'getter-return': 'error',
            'no-async-promise-executor': 'error',
            'no-compare-neg-zero': 'error',
            'no-cond-assign': 'error',
            'no-const-assign': 'error',
            'no-constant-binary-expression': 'error',
            'no-debugger': 'error',
            'no-dupe-args': 'error',
            'no-dupe-class-members': 'error',
            'no-dupe-else-if': 'error',
            'no-dupe-keys': 'error',
            'no-duplicate-case': 'error',
            'no-empty-pattern': 'error',
            'no-func-assign': 'error',
            'no-import-assign': 'error',
            'no-loss-of-precision': 'error',
            'no-self-assign': 'error',
            'no-self-compare': 'error',
            'no-setter-return': 'error',
            'no-sparse-arrays': 'error',
            'no-this-before-super': 'error',
            'no-unreachable': 'error',
            'no-unsafe-finally': 'error',
            'no-unsafe-negation': 'error',
            'no-unsafe-optional-chaining': 'error',
            'use-isnan': 'error',
            'valid-typeof': 'error',
        },
    },
];
