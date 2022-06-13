import React, { ChangeEvent, useCallback, useMemo, useRef, useState } from 'react';
import styled from 'styled-components';

import { useWorkspaces } from '../../provider/WorkspaceProvider';
import { FormGroup, Icon, RadioLabel, ValidationMessage } from '../presentationals';
import { ActionBar } from './StyledModal';

const Form = styled.form`
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

type FormProps = {
    enabled: boolean;
    onSubmit: (formData: FormData) => void;
    onCancel: () => void;
    submitLabel: string;
    workspace?: Workspace;
};

const WorkspaceForm: React.FC<FormProps> = ({ enabled, onSubmit, onCancel, submitLabel, workspace }) => {
    const { csrfToken, translate, validation, baseWorkspaceOptions, userCanManageInternalWorkspaces, ownerOptions } =
        useWorkspaces();
    const workspaceForm = useRef<HTMLFormElement>(null);
    const ownerField = useRef<HTMLSelectElement>(null);
    const [title, setTitle] = useState(workspace?.title ? workspace.title : '');
    const [owner, setOwner] = useState(workspace?.owner ? workspace.owner : '');

    const updateTitle = useCallback((event: ChangeEvent<HTMLInputElement>) => {
        if (event.target.value) {
            setTitle(event.target.value);
        }
    }, []);

    const updateOwner = useCallback((event: ChangeEvent<HTMLSelectElement>) => {
        if (event.target.value) {
            setOwner(event.target.value);
        }
    }, []);

    const currentOwner = useMemo(() => {
        return workspace?.owner ? Object.keys(ownerOptions).find((name) => ownerOptions[name] === workspace.owner) : '';
    }, [workspace?.owner]);

    const titleIsValid = useMemo(() => {
        const regex = new RegExp(validation.titlePattern);
        return regex.test(title);
    }, [title]);

    const selectableBaseWorkspaceNames = useMemo(() => {
        const workspaceNames = Object.keys(baseWorkspaceOptions);
        return workspace ? workspaceNames.filter((workspaceName) => workspaceName !== workspace.name) : workspaceNames;
    }, [workspace, baseWorkspaceOptions]);

    const handleSubmit = useCallback(() => {
        onSubmit(new FormData(workspaceForm.current));
    }, [workspaceForm.current]);

    return (
        <Form ref={workspaceForm}>
            <input type="hidden" name={'__csrfToken'} value={csrfToken} />
            {workspace && (
                <input type="hidden" name={'moduleArguments[workspace][__identity]'} value={workspace.name} />
            )}
            <label>
                {translate('workspace.title.label', 'Title')}
                <input
                    type="text"
                    name={'moduleArguments[title]'}
                    maxLength={200}
                    required
                    onChange={updateTitle}
                    defaultValue={workspace?.title || ''}
                />
                {title && !titleIsValid && (
                    <ValidationMessage dangerouslySetInnerHTML={{ __html: translate('workspace.title.validation') }} />
                )}
            </label>
            <label>
                {translate('workspace.description.label', 'Description')}
                <input
                    type="text"
                    name={'moduleArguments[description]'}
                    defaultValue={workspace?.description || ''}
                    maxLength={500}
                />
            </label>
            <label>
                {translate('workspace.baseWorkspace.label', 'Base Workspace')}
                <select
                    name={'moduleArguments[workspace][baseWorkspace]'}
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
            {/*TODO: Allow setting an owner already during creation */}
            {workspace ? (
                <>
                    {!workspace.isPersonal && (
                        <label>
                            {translate('workspace.owner.label', 'Owner')}
                            <select
                                name={'moduleArguments[workspace][owner]'}
                                disabled={!userCanManageInternalWorkspaces}
                                defaultValue={currentOwner}
                                ref={ownerField}
                                onChange={updateOwner}
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
                        {workspace.isPersonal
                            ? translate('workspace.visibility.isPersonal', 'This workspace is personal')
                            : owner
                            ? translate('workspace.visibility.private.info', 'This workspace is private')
                            : translate('workspace.visibility.internal.info', 'This workspace is internal')}
                    </p>
                </>
            ) : (
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
            )}
            <ActionBar>
                <button type="button" className="neos-button" onClick={onCancel}>
                    {translate('dialog.action.cancel', 'Cancel')}
                </button>
                <button
                    type="button"
                    id="createWorkspaceDialogSubmit"
                    className="neos-button neos-button-primary"
                    onClick={handleSubmit}
                    disabled={!enabled || !titleIsValid}
                >
                    {submitLabel}
                </button>
            </ActionBar>
        </Form>
    );
};

export default React.memo(WorkspaceForm);
