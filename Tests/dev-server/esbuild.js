const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
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

/**
 * The hash of the bundle of the server.
 *
 * The watch rebuilds the bundle for every change in the folder of the server - the output of
 * the build is in it, and so are the modules of the dependencies. The server is only restarted
 * when its bundle really changed: a server that is killed and started again drops the
 * connections of the tests that are running against it.
 */
const hashOfServerBundle = () => {
    try {
        return crypto.createHash('sha1').update(fs.readFileSync(path.join(__dirname, 'dist/server.js'))).digest('hex');
    } catch (error) {
        return null;
    }
};

// Restart the express server whenever its bundle was rebuilt
const restartServerPlugin = {
    name: 'restart-server',
    setup(build) {
        let runningHash = null;

        build.onEnd((result) => {
            if (result.errors.length > 0) {
                return;
            }

            const hash = hashOfServerBundle();
            if (hash === null || hash === runningHash) {
                return;
            }

            runningHash = hash;
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
