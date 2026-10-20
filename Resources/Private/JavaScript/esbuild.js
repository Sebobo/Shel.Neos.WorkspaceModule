const esbuild = require('esbuild');
const isWatch = process.argv.includes('--watch');

/** @type {import('esbuild').BuildOptions} */
const options = {
    logLevel: 'info',
    bundle: true,
    minify: !isWatch,
    target: 'es2020',
    // The Neos backend loads the module as a regular script, so no ESM output
    format: 'iife',
    entryPoints: { main: 'index.tsx' },
    outdir: '../../Public/Assets',
    sourcemap: true,
    // React is only used for its types, the runtime is provided by preact/compat
    // which is roughly 120kb smaller than react-dom
    alias: {
        react: 'preact/compat',
        'react-dom': 'preact/compat',
        'react-dom/client': 'preact/compat/client',
    },
};

if (isWatch) {
    esbuild.context(options).then((ctx) => ctx.watch());
} else {
    esbuild.build(options);
}
