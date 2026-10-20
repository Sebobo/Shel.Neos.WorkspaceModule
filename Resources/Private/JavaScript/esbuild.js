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
};

if (isWatch) {
    esbuild.context(options).then((ctx) => ctx.watch());
} else {
    esbuild.build(options);
}
