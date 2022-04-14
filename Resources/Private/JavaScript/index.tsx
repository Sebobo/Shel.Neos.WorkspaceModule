import * as React from 'react';
import { createRoot } from 'react-dom/client';
import WorkspaceModule from './components/WorkspaceModule';
import { WorkspaceProvider } from './provider/WorkspaceProvider';
import { IntlProvider } from './provider/IntlProvider';

window.onload = async (): Promise<void> => {
    while (!window.NeosCMS?.I18n?.initialized) {
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    const container = document.getElementById('workspace-module-app');
    const workspaceDataTag = document.getElementById('workspaces');

    const { userWorkspace } = container.dataset;
    const endpoints = JSON.parse(container.dataset.endpoints);
    const workspaces = JSON.parse(workspaceDataTag.textContent);

    const { I18n, Notification } = window.NeosCMS;

    const translate = (id: string, label = '', args = []): string => {
        return I18n.translate(id, label, 'Shel.Neos.WorkspaceModule', 'Main', args);
    };

    const root = createRoot(container);
    root.render(
        <WorkspaceProvider value={{ workspaces, userWorkspace, endpoints }}>
            <IntlProvider translate={translate}>
                <WorkspaceModule />
            </IntlProvider>
        </WorkspaceProvider>
    );
};
