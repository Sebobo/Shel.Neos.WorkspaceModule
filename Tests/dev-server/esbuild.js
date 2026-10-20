const esbuild = require('esbuild');
const path = require('path');
const { spawn } = require('child_process');

const isWatch = process.argv.includes('--watch');

/** @type {import('esbuild').BuildOptions} */
const browserOptions = {
    logLevel: 'info',
    bundle: true,
    target: 'es2020',
    entryPoints: { index: 'index.ts' },
    outdir: 'dist',
    sourcemap: true,
};

/** @type {import('esbuild').BuildOptions} */
const serverOptions = {
    logLevel: 'info',
    bundle: true,
    platform: 'node',
    target: 'node22',
    format: 'cjs',
    // Keep node modules external so they are resolved at runtime
    packages: 'external',
    entryPoints: { server: 'server.ts' },
    outdir: 'dist',
};

let serverProcess = null;

const startServer = () => {
    if (serverProcess) {
        serverProcess.kill();
    }

    serverProcess = spawn(process.execPath, [path.join(__dirname, 'dist/server.js')], { stdio: 'inherit' });
};

// Restart the express server whenever its bundle was rebuilt
const restartServerPlugin = {
    name: 'restart-server',
    setup(build) {
        build.onEnd((result) => {
            if (result.errors.length > 0) {
                return;
            }

            startServer();
        });
    },
};

const run = async () => {
    const browserContext = await esbuild.context(browserOptions);
    const serverContext = await esbuild.context({ ...serverOptions, plugins: [restartServerPlugin] });

    if (isWatch) {
        await browserContext.watch();
        await serverContext.watch();

        console.info('Watching for changes. The dev server is running on http://localhost:4000');
    } else {
        await browserContext.rebuild();
        await serverContext.rebuild();
    }
};

process.on('SIGINT', () => {
    serverProcess?.kill();
    process.exit(0);
});

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
