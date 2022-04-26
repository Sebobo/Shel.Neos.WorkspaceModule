import React, { useMemo } from 'react';
import { useWorkspaces } from '../provider/WorkspaceProvider';
import styled from 'styled-components';

const WorkspaceCount = styled.div`
    display: inline-block;
    color: var(--textSubtleLight);
    font-size: var(--generalFontSize);
    line-height: var(--unit);
    vertical-align: middle;
    margin-left: var(--spacing-Full);
`;

const Footer: React.FC = () => {
    const { setCreationDialogVisible, workspaces } = useWorkspaces();

    const workspaceCount = useMemo(() => {
        return Object.values(workspaces).reduce(
            (counts, workspace) => {
                counts.total++;
                if (workspace.isInternal) {
                    counts.internal++;
                } else {
                    counts.private++;
                }
                return counts;
            },
            {
                total: 0,
                internal: 0,
                private: 0,
            }
        );
    }, [workspaces]);

    return (
        <div className="neos-footer">
            <button
                type="button"
                className="neos-button neos-button-success"
                onClick={() => setCreationDialogVisible(true)}
            >
                Create new workspace
            </button>
            <WorkspaceCount>
                {workspaceCount.total} workspaces ({workspaceCount.internal} public, {workspaceCount.private} private)
            </WorkspaceCount>
        </div>
    );
};

export default React.memo(Footer);
