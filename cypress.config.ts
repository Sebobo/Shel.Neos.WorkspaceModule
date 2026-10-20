import { existsSync } from 'fs';
import { join } from 'path';
import { defineConfig } from 'cypress';

export default defineConfig({
    e2e: {
        setupNodeEvents(on, config) {
            const pluginsFile = join(__dirname, 'cypress/plugins/index.js');

            // The plugins folder is not part of the repository, so the setup is optional
            return existsSync(pluginsFile) ? require(pluginsFile)(on, config) : config;
        },
        specPattern: 'Tests/integration/**/*.cy.{js,jsx,ts,tsx}',
        baseUrl: 'http://localhost:4000',
        supportFile: false,
    },
});
