import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActionBar, DialogHeader, StyledModal } from './StyledModal';
import { useWorkspaces } from '../../provider/WorkspaceProvider';
import styled from 'styled-components';
import Icon from '../Icon';

const RadioLabel = styled.label``;

const FormGroup = styled.div`
    margin-top: 1rem;
    & > span {
        display: block;
        margin-bottom: 0.5em;
    }
`;

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

const CreateWorkspaceDialog: React.FC = () => {
    const {
        csrfToken,
        createWorkspace,
        creationDialogVisible,
        setCreationDialogVisible,
        baseWorkspaceOptions,
        validation,
    } = useWorkspaces();
    const [isLoading, setIsLoading] = useState(false);
    const [workspaceTitle, setWorkspaceTitle] = useState('');
    const createForm = useRef<HTMLFormElement>(null);
    const titleField = useRef<HTMLInputElement>(null);

    const handleTitleChange = useCallback(() => {
        setWorkspaceTitle(titleField.current?.value || '');
        // TODO: Show validation message if needed
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
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>Create new workspace</DialogHeader>
            <EditForm ref={createForm}>
                <input type="hidden" name={'__csrfToken'} value={csrfToken} />
                <label>
                    Title
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
                        <ValidationMessage>
                            <b>Allowed title pattern: </b>
                            <ul>
                                <li>Numbers</li>
                                <li>Letters (upper & lowercase)</li>
                                <li>Special characters: - _ [ ] . ( )</li>
                                <li>First character needs to be an uppercase letter</li>
                                <li>No trailing whitespace</li>
                            </ul>
                        </ValidationMessage>
                    )}
                </label>
                <label>
                    Description
                    <input type="text" name={'moduleArguments[description]'} maxLength={500} />
                </label>
                <label>
                    Base Workspace
                    <select name={'moduleArguments[baseWorkspace]'} defaultValue="live">
                        {Object.keys(baseWorkspaceOptions).map((workspaceName) => (
                            <option key={workspaceName} value={workspaceName}>
                                {baseWorkspaceOptions[workspaceName]}
                            </option>
                        ))}
                    </select>
                </label>
                <FormGroup>
                    <label className="neos-control-label">Visiblity</label>
                    <RadioLabel className="neos-radio">
                        <input type="radio" name="moduleArguments[visibility]" defaultChecked value="internal" />
                        <span />
                        <span>Public – Any logged in editor can see and modify this workspace.</span>
                    </RadioLabel>
                    <RadioLabel className="neos-radio">
                        <input type="radio" name="moduleArguments[visibility]" value="private" />
                        <span />
                        <span>Private – Only reviewers and administrators can access and modify this workspace.</span>
                    </RadioLabel>
                </FormGroup>
            </EditForm>
            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    Cancel
                </button>
                <button
                    type="button"
                    className="neos-button neos-button-primary"
                    onClick={handleCommit}
                    disabled={isLoading || !titleField.current?.validity.valid}
                >
                    Save changes
                </button>
            </ActionBar>
        </StyledModal>
    );
};

export default React.memo(CreateWorkspaceDialog);
