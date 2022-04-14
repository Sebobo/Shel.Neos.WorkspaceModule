import * as React from 'react';

import Icon from './Icon';
import styled from 'styled-components';
import { useWorkspaces } from '../provider/WorkspaceProvider';

type WorkspaceTableRowProps = {
    workspaceName: WorkspaceName;
    level: number;
};

const AddedBadge = styled.span`
    background-color: var(--green);
    border-radius: 15%;
    color: var(--textOnGray);
    padding: 0.2em 0.5em;
    width: 33%;
    user-select: none;
    cursor: help;
`;

const ChangedBadge = styled(AddedBadge)`
    background-color: var(--warningText);
`;

const DeletedBadge = styled(AddedBadge)`
    background-color: var(--errorText);
`;

const Column = styled.td`
    padding: 0 0.5em;
    border-top: 1px solid var(--grayDark);
    background-color: var(--grayMedium);
`;

const TypeColumn = styled(Column)`
    text-align: center;
`;

const WorkspaceTableRow: React.FC<WorkspaceTableRowProps> = ({ workspaceName, level }) => {
    const { workspaces, editWorkspace, deleteWorkspace } = useWorkspaces();
    const workspace = workspaces[workspaceName];
    const icon = workspace.isInternal ? 'users' : 'user';

    return (
        <tr>
            <TypeColumn>
                <Icon icon={icon} />
            </TypeColumn>
            <Column title={workspace.name}>
                {workspace.baseWorkspace?.name !== 'live' && (
                    <Icon icon="caret-right" style={{ marginLeft: `${level - 1}rem`, marginRight: '0.5rem' }} />
                )}
                {workspace.title}
            </Column>
            <Column>{workspace.description || '–'}</Column>
            <Column>{workspace.baseWorkspace?.title || '–'}</Column>
            <Column>{workspace.creator || '–'}</Column>
            <Column>
                {workspace.lastModifiedBy ? `${workspace.lastModifiedBy} ${workspace.lastModifiedDate}` : '–'}
            </Column>
            <Column>
                {workspace.changesCounts === null ? (
                    <Icon icon="spinner" spin />
                ) : workspace.changesCounts.total > 0 ? (
                    <>
                        <AddedBadge title={`${workspace.changesCounts.new} new nodes were added`}>
                            {workspace.changesCounts.new}
                        </AddedBadge>{' '}
                        <ChangedBadge title={`${workspace.changesCounts.changed} nodes were changed`}>
                            {workspace.changesCounts.changed}
                        </ChangedBadge>{' '}
                        <DeletedBadge title={`${workspace.changesCounts.removed} nodes were removed`}>
                            {workspace.changesCounts.removed}
                        </DeletedBadge>
                    </>
                ) : (
                    'None'
                )}
            </Column>
            <Column>
                <button className="neos-button" type="button" title="Edit" onClick={() => editWorkspace(workspaceName)}>
                    <Icon icon="pencil-alt" />
                </button>
                <button
                    className="neos-button neos-button-danger"
                    type="button"
                    title="Delete"
                    disabled={!workspace.canManage}
                    onClick={() => deleteWorkspace(workspaceName)}
                >
                    <Icon icon="trash-alt" />
                </button>
            </Column>
        </tr>
    );
};

export default React.memo(WorkspaceTableRow);
