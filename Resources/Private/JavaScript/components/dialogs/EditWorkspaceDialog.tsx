import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActionBar, DialogHeader, StyledModal } from './StyledModal';
import { useWorkspaces } from '../../provider/WorkspaceProvider';
import styled from 'styled-components';
import Icon from '../Icon';

const EditForm = styled.form`
    width: 400px;
    max-width: 100%;

    & label {
        display: block;
        margin-bottom: 0.5rem;
    }

    .neos.neos-module & input,
    .neos.neos-module & select {
        display: block;
        width: 100%;
        margin-top: 0.3rem;
    }
`;

const EditWorkspaceDialog: React.FC = () => {
    const {
        workspaces,
        selectedWorkspaceForEdit,
        setSelectedWorkspaceForEdit,
        updateWorkspace,
        csrfToken,
        baseWorkspaceOptions,
        ownerOptions,
        userCanManageInternalWorkspaces,
    } = useWorkspaces();
    const [isLoading, setIsLoading] = useState(false);
    const [workspaceTitle, setWorkspaceTitle] = useState<string>('');
    const [workspaceDescription, setWorkspaceDescription] = useState<string>('');
    const [workspaceBaseWorkspace, setWorkspaceBaseWorkspace] = useState<WorkspaceName>('');
    const [workspaceOwner, setWorkspaceOwner] = useState<string>('');
    const editForm = useRef<HTMLFormElement>(null);

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForEdit], [selectedWorkspaceForEdit]);

    const handleChangeTitle = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
        setWorkspaceTitle(event.target.value);
    }, []);

    const handleChangeDescription = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
        setWorkspaceDescription(event.target.value);
    }, []);

    const handleChangeBaseWorkspace = useCallback((event: React.ChangeEvent<HTMLSelectElement>) => {
        setWorkspaceBaseWorkspace(event.target.value);
    }, []);

    const handleChangeOwner = useCallback((event: React.ChangeEvent<HTMLSelectElement>) => {
        setWorkspaceOwner(event.target.value);
    }, []);

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForEdit(null);
    }, []);

    const handleCommit = useCallback(() => {
        setIsLoading(true);
        updateWorkspace(new FormData(editForm.current)).then(() => {
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
    ]);

    useEffect(() => {
        if (!selectedWorkspace) return;
        setWorkspaceTitle(selectedWorkspace.title || '');
        setWorkspaceDescription(selectedWorkspace.description || '');
        setWorkspaceBaseWorkspace(selectedWorkspace.baseWorkspace.name || '');
        setWorkspaceOwner(selectedWorkspace.owner || '');
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
                        maxLength={200}
                    />
                </label>
                <label>
                    Description
                    <input
                        type="text"
                        name={'moduleArguments[workspace][description]'}
                        value={workspaceDescription}
                        onChange={handleChangeDescription}
                        maxLength={500}
                    />
                </label>
                <label>
                    Base Workspace
                    <select
                        name={'moduleArguments[workspace][baseWorkspace]'}
                        value={workspaceBaseWorkspace}
                        onChange={handleChangeBaseWorkspace}
                        disabled={selectedWorkspace.changesCounts.total > 1}
                    >
                        {Object.keys(baseWorkspaceOptions).map((workspaceName) =>
                            workspaceName !== selectedWorkspace.name ? (
                                <option key={workspaceName} value={workspaceName}>
                                    {baseWorkspaceOptions[workspaceName]}
                                </option>
                            ) : null
                        )}
                    </select>
                    {selectedWorkspace.changesCounts.total > 1 && (
                        <p style={{ marginTop: '.5em' }}>
                            <i
                                className="fas fa-exclamation-triangle"
                                style={{ color: 'var(--warningText)', marginRight: '.5em' }}
                            ></i>{' '}
                            You cannot change the base workspace of workspace with unpublished changes.
                        </p>
                    )}
                </label>
                {!selectedWorkspace.isPersonal && (
                    <label>
                        Owner
                        <select
                            name={'moduleArguments[workspace][owner]'}
                            value={workspaceOwner}
                            onChange={handleChangeOwner}
                            disabled={!userCanManageInternalWorkspaces}
                        >
                            {Object.keys(ownerOptions).map((userName) => (
                                <option key={userName} value={userName}>
                                    {ownerOptions[userName]}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <p>
                    <Icon icon="info-circle" style={{ color: 'var(--blue)', marginRight: '.5em' }} />
                    {selectedWorkspace.isPersonal
                        ? 'This is a personal workspace and only the owner can access and modify this workspace.'
                        : selectedWorkspace.isInternal
                        ? 'Any logged in editor can see and modify this workspace.'
                        : 'Only reviewers and administrators can access and modify this workspace.'}
                </p>
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
