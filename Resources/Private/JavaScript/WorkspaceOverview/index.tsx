import React from 'react';
import { createRoot } from 'react-dom/client';
import ReactModal from 'react-modal';

import WorkspaceModule from './components/WorkspaceModule';
import { WorkspaceProvider } from './provider/WorkspaceProvider';
import { IntlProvider } from './provider/IntlProvider';
import { NotifyProvider } from './provider/NotifyProvider';
import ErrorBoundary from './components/ErrorBoundary';

window.onload = async (): Promise<void> => {
    while (!(window as AppWindow).NeosCMS?.I18n?.initialized) {
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    const container = document.getElementById('workspace-module-app');
    if (!container) {
        return;
    }

    const workspaceModuleConfigurationDataElement = document.getElementById('workspace-module-configuration');
    const workspacesDataElement = document.getElementById('workspace-module-workspaces');
    const baseWorkspaceOptionsDataElement = document.getElementById('workspace-module-baseWorkspaceOptions');
    const userListDataElement = document.getElementById('workspace-module-userList');

    if (
        !workspaceModuleConfigurationDataElement ||
        !workspacesDataElement ||
        !baseWorkspaceOptionsDataElement ||
        !userListDataElement
    ) {
        container.innerText = 'Workspace module is missing required data elements';
        return;
    }

    const workspaceModuleConfiguration = JSON.parse(
        workspaceModuleConfigurationDataElement.textContent as string,
    ) as WorkspaceModuleConfiguration;
    const workspaces = JSON.parse(workspacesDataElement.textContent);
    const baseWorkspaceOptions = JSON.parse(baseWorkspaceOptionsDataElement.textContent);
    const userList = JSON.parse(userListDataElement.textContent);

    const { I18n, Notification } = (window as AppWindow).NeosCMS;

    const translate = (id: string, label = '', args = []): string => {
        return I18n.translate(id, label, 'Shel.Neos.WorkspaceModule', 'Main', args);
    };

    ReactModal.setAppElement(container);

    const root = createRoot(container);
    root.render(
        <ErrorBoundary>
            <IntlProvider translate={translate}>
                <NotifyProvider notificationApi={Notification}>
                    <WorkspaceProvider
                        {...workspaceModuleConfiguration}
                        workspaceList={workspaces}
                        baseWorkspaceOptions={baseWorkspaceOptions}
                        userList={userList}
                    >
                        <WorkspaceModule />
                    </WorkspaceProvider>
                </NotifyProvider>
            </IntlProvider>
        </ErrorBoundary>,
    );
};
