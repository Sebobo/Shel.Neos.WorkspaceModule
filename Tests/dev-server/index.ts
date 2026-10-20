import { loadFixtures } from './fixtures';

import '../../Resources/Private/JavaScript/index.tsx';

(() => {
    console.info('Started Workspace Module dev server script');

    const container = document.getElementById('workspace-module-app');

    if (!container) {
        return;
    }

    // Create Neos API mock
    (window as AppWindow).NeosCMS = {
        I18n: {
            initialized: true,
            // eslint-disable-next-line @typescript-eslint/no-unused-vars,no-unused-vars
            translate: (id, fallback: string, packageKey = null, source = null, args = []) => {
                Object.keys(args).forEach((key) => (fallback = fallback.replace(`{${key}}`, args[key])));
                return fallback;
            },
        },
        Notification: {
            notice: (title) => console.log(title),
            ok: (title) => console.log(title),
            error: (title, message) => console.error(message, title),
            warning: (title, message) => console.warn(message, title),
            info: (title) => console.info(title),
        },
    };

    const fixtures = loadFixtures();

    // The module expects the data as JSON script tags with the very same ids as in the Fusion view
    const addDataElement = (id: string, data: unknown): void => {
        const element = document.createElement('script');
        element.id = id;
        element.type = 'application/json';
        element.innerText = JSON.stringify(data);
        container.appendChild(element);
    };

    addDataElement('workspace-module-configuration', {
        username: 'sskinner',
        userWorkspace: 'user-sskinner',
        csrfToken: 'abc',
        userCanManageInternalWorkspaces: true,
        userCanCreateWorkspaces: true,
        validation: {
            titlePattern: '^[A-Z][\\s\\w\\d\\-\\.\\[\\]\\(\\)]+\\b(?!\\s)$',
        },
        endpoints: {
            deleteWorkspace: '/deleteWorkspace?workspace=---workspace---',
            pruneWorkspace: '/pruneWorkspace?workspace=---workspace---',
            updateWorkspace: '/updateWorkspace?workspace=---workspace---',
            createWorkspace: '/createWorkspace',
            showWorkspace: '/showWorkspace?workspace=---workspace---',
            getChanges: '/getChanges',
        },
    });
    addDataElement('workspace-module-workspaces', fixtures.workspaces);
    addDataElement('workspace-module-baseWorkspaceOptions', fixtures.baseWorkspaceOptions);
    addDataElement('workspace-module-userList', {
        sskinner: 'Sidney Skinner',
        apayne: 'Ashley Payne',
    });
})();
