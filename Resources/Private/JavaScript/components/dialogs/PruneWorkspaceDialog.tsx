import React, { useCallback, useMemo } from 'react';
import styled from 'styled-components';

import { useWorkspaces } from '../../provider/WorkspaceProvider';
import { DialogHeader, StyledModal, ActionBar } from './StyledModal';

const RebasedWorkspaceWrapper = styled.div`
    margin: 1rem 0;

    & ul {
        margin: 1rem 0;
    }

    & li {
        list-style-type: disc;
        margin: 0.3rem 0 0.3rem 1rem;
    }
`;

const PruneWorkspaceDialog: React.FC = () => {
    const { selectedWorkspaceForPruning, setSelectedWorkspaceForPruning, pruneWorkspace, workspaces, translate } =
        useWorkspaces();

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForPruning], [selectedWorkspaceForPruning]);
    const dependentWorkspaces = useMemo(() => {
        return Object.values(workspaces).filter(
            (workspace) => workspace.baseWorkspace.name === selectedWorkspaceForPruning
        );
    }, [selectedWorkspaceForPruning, workspaces]);

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForPruning(null);
    }, []);

    const handlePrune = useCallback(() => {
        pruneWorkspace(selectedWorkspaceForPruning);
        handleClose();
    }, [selectedWorkspaceForPruning]);

    return selectedWorkspaceForPruning && selectedWorkspace.nodeCount > 1 ? (
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>
                {translate('dialog.prune.header', `Prune "${selectedWorkspace.title}"?`, {
                    workspace: selectedWorkspace.title,
                })}
            </DialogHeader>

            <p
                dangerouslySetInnerHTML={{
                    __html: translate(
                        'dialog.prune.unpublishedChanges',
                        `Pruning this workspace will discard ${selectedWorkspace.nodeCount - 1} changes.`,
                        { count: selectedWorkspace.nodeCount - 1 }
                    ),
                }}
            />
            <p>{translate('dialog.prune.pointOfNoReturn', 'This action cannot be undone.')}</p>

            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    {translate('dialog.action.cancel', 'Cancel')}
                </button>
                <button type="button" className="neos-button neos-button-danger" onClick={handlePrune}>
                    {translate('dialog.action.prune', 'Prune')}
                </button>
            </ActionBar>
        </StyledModal>
    ) : null;
};

export default React.memo(PruneWorkspaceDialog);
