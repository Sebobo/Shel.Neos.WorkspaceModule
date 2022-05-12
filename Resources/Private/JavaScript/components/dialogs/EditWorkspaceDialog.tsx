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

const ValidationMessage = styled.div`
    color: red;
    font-size: 0.8rem;
    margin-top: 0.5rem;

    & ul {
        padding: 0 1rem;
    }

    & li {
        list-style-type: disc;
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
        validation,
        translate,
    } = useWorkspaces();
    const [isLoading, setIsLoading] = useState(false);
    const [workspaceTitle, setWorkspaceTitle] = useState<string>('');
    const [workspaceDescription, setWorkspaceDescription] = useState<string>('');
    const [workspaceBaseWorkspace, setWorkspaceBaseWorkspace] = useState<WorkspaceName>('');
    const [workspaceOwner, setWorkspaceOwner] = useState<string>('');
    const editForm = useRef<HTMLFormElement>(null);

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForEdit], [selectedWorkspaceForEdit]);
    const titleValid = useMemo(() => {
        const regex = new RegExp(validation.titlePattern);
        return regex.test(workspaceTitle);
    }, [workspaceTitle]);

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
    }, [selectedWorkspace]);

    console.debug(titleValid, 'titleField.current?.validity.valid');

    return selectedWorkspace ? (
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>
                {translate('dialog.edit.header', `Edit "${selectedWorkspace.title}"`, {
                    workspace: selectedWorkspace.title,
                })}
            </DialogHeader>
            <EditForm ref={editForm}>
                <input type="hidden" name={'__csrfToken'} value={csrfToken} />
                <input type="hidden" name={'moduleArguments[workspace][__identity]'} value={selectedWorkspace.name} />
                <label>
                    {translate('workspace.title.label')}
                    <input
                        type="text"
                        name={'moduleArguments[workspace][title]'}
                        defaultValue={selectedWorkspace.title}
                        onChange={handleChangeTitle}
                        maxLength={200}
                        required
                    />
                    {workspaceTitle && !titleValid && (
                        <ValidationMessage
                            dangerouslySetInnerHTML={{ __html: translate('workspace.title.validation') }}
                        />
                    )}
                </label>
                <label>
                    {translate('workspace.description.label')}
                    <input
                        type="text"
                        name={'moduleArguments[workspace][description]'}
                        value={workspaceDescription}
                        onChange={handleChangeDescription}
                        maxLength={500}
                    />
                </label>
                <label>
                    {translate('workspace.baseWorkspace.label')}
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
                        {translate('workspace.owner.label')}
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
                        ? translate('workspace.visibility.isPersonal')
                        : selectedWorkspace.isInternal
                        ? translate('workspace.visibility.private.info')
                        : translate('workspace.visibility.internal.info')}
                </p>
            </EditForm>
            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    {translate('dialog.action.cancel')}
                </button>
                <button
                    type="button"
                    className="neos-button neos-button-primary"
                    onClick={handleCommit}
                    disabled={isLoading || !titleValid}
                >
                    {translate('dialog.action.update')}
                </button>
            </ActionBar>
        </StyledModal>
    ) : null;
};

export default React.memo(EditWorkspaceDialog);
