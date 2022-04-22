import React, { useCallback, useMemo } from 'react';
import styled from 'styled-components';

import { useWorkspaces } from '../../provider/WorkspaceProvider';
import { DialogHeader, StyledModal, ActionBar } from './StyledModal';

const RebasedWorkspaceWrapper = styled.p`
    margin: 1rem 0;

    & li {
        list-style-type: disc;
        margin: 0.3rem 0 0.3rem 1rem;
    }
`;

const DeleteWorkspaceDialog: React.FC = () => {
    const { selectedWorkspaceForDeletion, setSelectedWorkspaceForDeletion, deleteWorkspace, workspaces } =
        useWorkspaces();

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForDeletion], [selectedWorkspaceForDeletion]);
    const dependentWorkspaces = useMemo(() => {
        return Object.values(workspaces).filter(
            (workspace) => workspace.baseWorkspace.name === selectedWorkspaceForDeletion
        );
    }, [selectedWorkspaceForDeletion, workspaces]);

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForDeletion(null);
    }, []);

    const handleDelete = useCallback(() => {
        deleteWorkspace(selectedWorkspaceForDeletion);
        handleClose();
    }, [selectedWorkspaceForDeletion]);

    return selectedWorkspaceForDeletion ? (
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>Delete "{selectedWorkspace.title}"?</DialogHeader>

            {selectedWorkspace.changesCounts.total > 0 && (
                <p>
                    Deleting this workspace will also delete <strong>{selectedWorkspace.changesCounts.total}</strong>{' '}
                    unpublished changes.
                </p>
            )}
            {selectedWorkspace.dependentWorkspacesCount > 0 && (
                <RebasedWorkspaceWrapper>
                    <i
                        className="fas fa-exclamation-triangle"
                        style={{ color: 'var(--warningText)', marginRight: '.5em' }}
                    ></i>{' '}
                    This action will also rebase the following workspaces:
                    <ul>
                        {dependentWorkspaces.map((child) => (
                            <li key={child.title}>{child.title}</li>
                        ))}
                    </ul>
                </RebasedWorkspaceWrapper>
            )}
            <p>This action cannot be undone.</p>

            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    Cancel
                </button>
                <button type="button" className="neos-button neos-button-danger" onClick={handleDelete}>
                    Delete this workspace
                </button>
            </ActionBar>
        </StyledModal>
    ) : null;
};

export default React.memo(DeleteWorkspaceDialog);
