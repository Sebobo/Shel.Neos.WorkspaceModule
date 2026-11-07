const esbuild = require('esbuild');
const isWatch = process.argv.includes('--watch');

/**
 * The build of the review.
 *
 * The review is plain JavaScript and plain CSS - there is no framework and no import of a
 * module. The build bundles them, minifies them and writes them where the backend loads
 * them from.
 *
 * @type {import('esbuild').BuildOptions}
 */
const options = {
    logLevel: 'info',
    bundle: true,
    minify: !isWatch,
    target: 'es2020',
    // The backend loads the module as a regular script, so no ESM output
    format: 'iife',
    entryPoints: {
        'Scripts/Module': 'Module.js',
        'Styles/Module': 'Module.css',
    },
    outdir: '../../../Public',
    sourcemap: true,
};

if (isWatch) {
    esbuild.context(options).then((ctx) => ctx.watch());
} else {
    esbuild.build(options);
}
