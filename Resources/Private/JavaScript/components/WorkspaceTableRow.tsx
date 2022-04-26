import * as React from 'react';

import Icon from './Icon';
import styled from 'styled-components';
import { useWorkspaces } from '../provider/WorkspaceProvider';
import { formatDate } from '../helper/format';
import ArrowIcon from './ArrowIcon';

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

    & + * {
        margin-left: 0.5em;
    }
`;

const ChangedBadge = styled(AddedBadge)`
    background-color: var(--warningText);
`;

const DeletedBadge = styled(AddedBadge)`
    background-color: var(--errorText);
`;

const OrphanBadge = styled(AddedBadge)`
    background-color: var(--grayLight);
`;

const StaleBadge = styled(AddedBadge)`
    background-color: var(--warningText);
    margin-left: 0.5em;
`;

const Column = styled.td`
    padding: 0 0.5em;
    border-top: 1px solid var(--grayDark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
`;

const TextColumn = styled(Column)`
    max-width: 1px;
    width: 25%;
`;

const ActionColumn = styled(Column)`
    padding: 0;
    background-color: var(--grayLight);

    & .neos-button[disabled] {
        opacity: 1;
        color: var(--textSubtle);

        &:hover {
            color: var(--textSubtle);
        }
    }
`;

const TypeColumn = styled(Column)`
    text-align: center;

    & > i {
        width: auto !important;
    }
`;

const Row = styled.tr<{ isUserWorkspace: boolean; isStale: boolean }>`
    & > ${Column} {
        background-color: ${(props) =>
            props.isUserWorkspace ? 'var(--blueDark)' : props.isStale ? 'var(--grayDark)' : 'var(--grayMedium)'};
    }
`;

const InfoText = styled.span`
    font-size: 0.8em;
    font-style: italic;
    margin-left: 0.5em;
    user-select: none;
`;

const WorkspaceTableRow: React.FC<WorkspaceTableRowProps> = ({ workspaceName, level }) => {
    const { userWorkspace, workspaces, setSelectedWorkspaceForEdit, setSelectedWorkspaceForDeletion, showWorkspace } =
        useWorkspaces();
    const workspace = workspaces[workspaceName];
    const icon = workspace.isInternal ? 'users' : 'user';
    const isUserWorkspace = workspaceName === userWorkspace;
    const nodeCountNotCoveredByChanges = workspace.nodeCount - (workspace.changesCounts?.total || 0) - 1;

    return (
        <Row isUserWorkspace={isUserWorkspace} isStale={workspace.isStale}>
            <TypeColumn>
                <Icon icon={icon} />
            </TypeColumn>
            <TextColumn title={workspace.name}>
                <span>
                    {workspace.baseWorkspace?.name !== 'live' && (
                        <ArrowIcon style={{ marginLeft: `${0.2 + (level - 1) * 1.2}rem`, marginRight: '0.5rem' }} />
                    )}
                    {workspace.title}
                    {workspace.isStale || isUserWorkspace ? (
                        <>
                            {workspace.isStale && <StaleBadge title="Workspace is stale">!</StaleBadge>}
                            {isUserWorkspace && <InfoText>(This is your workspace)</InfoText>}
                        </>
                    ) : null}
                </span>
            </TextColumn>
            <TextColumn title={workspace.description}>{workspace.description || '–'}</TextColumn>
            <Column>{workspace.creator || '–'}</Column>
            <Column>
                {workspace.lastChangedBy ? `${workspace.lastChangedBy} ${formatDate(workspace.lastChangedDate)}` : '–'}
            </Column>
            <Column>
                {workspace.changesCounts === null ? (
                    <Icon icon="spinner" spin />
                ) : workspace.changesCounts.total > 0 ? (
                    <>
                        {workspace.changesCounts.new > 0 && (
                            <AddedBadge title={`${workspace.changesCounts.new} new nodes were added`}>
                                {workspace.changesCounts.new}
                            </AddedBadge>
                        )}
                        {workspace.changesCounts.changed > 0 && (
                            <ChangedBadge title={`${workspace.changesCounts.changed} nodes were changed`}>
                                {workspace.changesCounts.changed}
                            </ChangedBadge>
                        )}
                        {workspace.changesCounts.removed > 0 && (
                            <DeletedBadge title={`${workspace.changesCounts.removed} nodes were removed`}>
                                {workspace.changesCounts.removed}
                            </DeletedBadge>
                        )}
                    </>
                ) : nodeCountNotCoveredByChanges > 0 ? (
                    <OrphanBadge title={`${nodeCountNotCoveredByChanges} nodes were changed but might be orphaned`}>
                        {nodeCountNotCoveredByChanges}
                    </OrphanBadge>
                ) : isUserWorkspace ? (
                    '–'
                ) : (
                    'None'
                )}
            </Column>
            <ActionColumn>
                <button
                    className="neos-button"
                    type="button"
                    title={`Show changes in workspace ${workspace.title}`}
                    disabled={!workspace.changesCounts?.total}
                    onClick={() => showWorkspace(workspaceName)}
                >
                    <Icon icon="review" />
                </button>
                <button
                    className="neos-button"
                    type="button"
                    title={`Edit workspace ${workspace.title}`}
                    onClick={() => setSelectedWorkspaceForEdit(workspaceName)}
                >
                    <Icon icon="pencil-alt" />
                </button>
                <button
                    className="neos-button neos-button-danger"
                    type="button"
                    title={`Delete workspace ${workspace.title}`}
                    disabled={!workspace.canManage}
                    onClick={() => setSelectedWorkspaceForDeletion(workspaceName)}
                >
                    <Icon icon="trash-alt" />
                </button>
            </ActionColumn>
        </Row>
    );
};

export default React.memo(WorkspaceTableRow);
