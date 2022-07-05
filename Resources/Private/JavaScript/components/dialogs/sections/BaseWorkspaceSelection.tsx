import React, { useMemo } from 'react';

import { useWorkspaces } from '../../../provider/WorkspaceProvider';

type SectionProps = {
    workspace: Workspace;
};

const BaseWorkspaceSelection: React.FC<SectionProps> = ({ workspace }) => {
    const { translate, baseWorkspaceOptions } = useWorkspaces();

    const selectableBaseWorkspaceNames = useMemo(() => {
        const workspaceNames = Object.keys(baseWorkspaceOptions);
        return workspace ? workspaceNames.filter((workspaceName) => workspaceName !== workspace.name) : workspaceNames;
    }, [workspace, baseWorkspaceOptions]);

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
