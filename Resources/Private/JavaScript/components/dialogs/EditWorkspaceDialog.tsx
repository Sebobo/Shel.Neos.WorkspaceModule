import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActionBar, DialogHeader, StyledModal } from './StyledModal';
import { useWorkspaces } from '../../provider/WorkspaceProvider';
import styled from 'styled-components';

const EditForm = styled.form`
    & label {
        display: block;
        margin-bottom: 0.5rem;
    }

    .neos.neos-module & input {
        display: block;
        width: 100%;
        margin-top: 0.3rem;
    }
`;

const EditWorkspaceDialog: React.FC = () => {
    const { workspaces, selectedWorkspaceForEdit, setSelectedWorkspaceForEdit, updateWorkspace, csrfToken } =
        useWorkspaces();
    const [isLoading, setIsLoading] = useState(false);
    const [workspaceTitle, setWorkspaceTitle] = useState<string>('');
    const [workspaceDescription, setWorkspaceDescription] = useState<string>('');
    const [workspaceBaseWorkspace, setWorkspaceBaseWorkspace] = useState<WorkspaceName>('');
    const [workspaceOwner, setWorkspaceOwner] = useState<string>('');
    const [workspaceVisibility, setWorkspaceVisibility] = useState<string>('');
    const editForm = useRef<HTMLFormElement>(null);

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForEdit], [selectedWorkspaceForEdit]);

    const handleChangeTitle = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
        setWorkspaceTitle(event.target.value);
    }, []);

    const handleChangeDescription = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
        setWorkspaceDescription(event.target.value);
    }, []);

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForEdit(null);
    }, []);

    const handleCommit = useCallback(() => {
        setIsLoading(true);
        updateWorkspace(new FormData(editForm.current)).then((data) => {
            setSelectedWorkspaceForEdit(null);
            setIsLoading(false);
        });
    }, [
        updateWorkspace,
        selectedWorkspaceForEdit,
        workspaceTitle,
        workspaceDescription,
        workspaceBaseWorkspace,
        workspaceOwner,
        workspaceVisibility,
    ]);

    useEffect(() => {
        if (!selectedWorkspace) return;
        setWorkspaceTitle(selectedWorkspace.title || '');
        setWorkspaceDescription(selectedWorkspace.description || '');
    }, [selectedWorkspaceForEdit]);

    return selectedWorkspaceForEdit ? (
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>Edit "{selectedWorkspace.title}"</DialogHeader>
            <EditForm ref={editForm}>
                <input type="hidden" name={'__csrfToken'} value={csrfToken} />
                <input type="hidden" name={'moduleArguments[workspace][__identity]'} value={selectedWorkspace.name} />
                <label>
                    Title
                    <input
                        type="text"
                        name={'moduleArguments[workspace][title]'}
                        value={workspaceTitle}
                        onChange={handleChangeTitle}
                    />
                </label>
                <label>
                    Description
                    <input
                        type="text"
                        name={'moduleArguments[workspace][description]'}
                        value={workspaceDescription}
                        onChange={handleChangeDescription}
                    />
                </label>
            </EditForm>
            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    Cancel
                </button>
                <button
                    type="button"
                    className="neos-button neos-button-primary"
                    onClick={handleCommit}
                    disabled={isLoading}
                >
                    Save changes
                </button>
            </ActionBar>
        </StyledModal>
    ) : null;
};

export default React.memo(EditWorkspaceDialog);
