import React, { useMemo } from 'react';

import { useWorkspaces } from '../../../provider/WorkspaceProvider';

type SectionProps = {
    workspace: Workspace;
};

const BaseWorkspaceSelection: React.FC<SectionProps> = ({ workspace }) => {
    const { translate, baseWorkspaceOptions, workspaces } = useWorkspaces();

    const selectableBaseWorkspaceNames = useMemo(() => {
        const workspaceNames = Object.keys(baseWorkspaceOptions);
        if (!workspace) {
            return workspaceNames;
        }
        // Check if the selection of the workspace would create a cycle
        return workspaceNames.filter((workspaceName) => {
            // The live workspace is always selectable
            if (workspaceName === 'live') {
                return true;
            }
            // Don't allow to select the current workspace as base workspace
            if (workspaceName === workspace.name) {
                return false;
            }
            const workspaceToCheck = workspaces[workspaceName];
            // Allow to selecting workspaces as base workspace if they have no base workspace
            if (!workspaceToCheck.baseWorkspace) {
                return true;
            }
            // Don't allow to selecting workspaces that have the current workspace in their base workspace chain
            let baseWorkspaceName = workspaceToCheck.baseWorkspace?.name;
            while (baseWorkspaceName && baseWorkspaceName !== 'live') {
                if (baseWorkspaceName === workspace.name) {
                    return false;
                }
                baseWorkspaceName = workspaces[baseWorkspaceName].baseWorkspace?.name;
            }
            return true;
        });
    }, [workspace, baseWorkspaceOptions, workspaces]);

    return (
        <label>
            {translate('workspace.baseWorkspace.label', 'Base Workspace')}
            <select
                name={`moduleArguments${workspace ? '[workspace]' : ''}[baseWorkspace]`}
                disabled={workspace?.changesCounts.total > 1}
                defaultValue={workspace?.baseWorkspace.name || ''}
            >
                {selectableBaseWorkspaceNames.map((workspaceName) => (
                    <option key={workspaceName} value={workspaceName}>
                        {baseWorkspaceOptions[workspaceName]}
                    </option>
                ))}
            </select>
            {workspace?.changesCounts.total > 1 && (
                <p style={{ marginTop: '.5em' }}>
                    <i
                        className="fas fa-exclamation-triangle"
                        style={{ color: 'var(--warningText)', marginRight: '.5em' }}
                    ></i>{' '}
                    You cannot change the base workspace of workspace with unpublished changes.
                </p>
            )}
        </label>
    );
};

export default React.memo(BaseWorkspaceSelection);
