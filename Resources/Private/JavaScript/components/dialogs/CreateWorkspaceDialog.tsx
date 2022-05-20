import React, { useCallback, useRef, useState } from 'react';
import styled from 'styled-components';

import { ActionBar, DialogHeader, StyledModal } from './StyledModal';
import { useWorkspaces } from '../../provider/WorkspaceProvider';
import { FormGroup, ValidationMessage, RadioLabel } from '../presentationals';

const EditForm = styled.form`
    width: 400px;
    max-width: 100%;

    & label {
        display: block;
        margin-bottom: 0.5rem;
    }

    & ${RadioLabel} {
        display: flex;

        & input {
            margin-right: 0.5rem;
        }
    }

    .neos.neos-module & input[type='text'],
    .neos.neos-module & select {
        display: block;
        width: 100%;
        margin-top: 0.3rem;
    }
`;

const CreateWorkspaceDialog: React.FC = () => {
    const {
        csrfToken,
        createWorkspace,
        creationDialogVisible,
        setCreationDialogVisible,
        baseWorkspaceOptions,
        validation,
        translate,
    } = useWorkspaces();
    const [isLoading, setIsLoading] = useState(false);
    const [workspaceTitle, setWorkspaceTitle] = useState('');
    const createForm = useRef<HTMLFormElement>(null);
    const titleField = useRef<HTMLInputElement>(null);

    const handleTitleChange = useCallback(() => {
        setWorkspaceTitle(titleField.current?.value || '');
    }, [titleField.current]);

    const handleClose = useCallback(() => {
        setCreationDialogVisible(false);
        setWorkspaceTitle('');
        createForm.current.reset();
    }, [createForm.current, setCreationDialogVisible, setWorkspaceTitle]);

    const handleCommit = useCallback(() => {
        setIsLoading(true);
        createWorkspace(new FormData(createForm.current)).then(() => {
            setIsLoading(false);
            handleClose();
        });
    }, [createWorkspace, handleClose]);

    if (!creationDialogVisible) return null;

    return (
        <StyledModal isOpen onRequestClose={handleClose} id="createWorkspaceDialog">
            <DialogHeader>{translate('dialog.create.header', 'Create new workspace')}</DialogHeader>
            <EditForm ref={createForm}>
                <input type="hidden" name={'__csrfToken'} value={csrfToken} />
                <label>
                    {translate('workspace.title.label', 'Title')}
                    <input
                        type="text"
                        name={'moduleArguments[title]'}
                        maxLength={200}
                        pattern={validation.titlePattern}
                        required
                        ref={titleField}
                        onChange={handleTitleChange}
                        value={workspaceTitle}
                    />
                    {workspaceTitle && !titleField.current?.validity.valid && (
                        <ValidationMessage
                            dangerouslySetInnerHTML={{ __html: translate('workspace.title.validation') }}
                        />
                    )}
                </label>
                <label>
                    {translate('workspace.description.label', 'Description')}
                    <input type="text" name={'moduleArguments[description]'} maxLength={500} />
                </label>
                <label>
                    {translate('workspace.baseWorkspace.label', 'Base workspace')}
                    <select name={'moduleArguments[baseWorkspace]'} defaultValue="live">
                        {Object.keys(baseWorkspaceOptions).map((workspaceName) => (
                            <option key={workspaceName} value={workspaceName}>
                                {baseWorkspaceOptions[workspaceName]}
                            </option>
                        ))}
                    </select>
                </label>
                <FormGroup>
                    <label className="neos-control-label">
                        {translate('workspace.visibility.label', 'Visibility')}
                    </label>
                    <RadioLabel className="neos-radio">
                        <input type="radio" name="moduleArguments[visibility]" defaultChecked value="internal" />
                        <span />
                        <span>{translate('workspace.visibility.internal', 'Internal')}</span>
                    </RadioLabel>
                    <RadioLabel className="neos-radio">
                        <input type="radio" name="moduleArguments[visibility]" value="private" />
                        <span />
                        <span>{translate('workspace.visibility.private', 'Private')}</span>
                    </RadioLabel>
                </FormGroup>
            </EditForm>
            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    {translate('dialog.action.cancel', 'Cancel')}
                </button>
                <button
                    type="button"
                    id="createWorkspaceDialogCreate"
                    className="neos-button neos-button-primary"
                    onClick={handleCommit}
                    disabled={isLoading || !titleField.current?.validity.valid}
                >
                    {translate('dialog.action.create', 'Create')}
                </button>
            </ActionBar>
        </StyledModal>
    );
};

export default React.memo(CreateWorkspaceDialog);
