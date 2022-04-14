import * as React from 'react';
import styled from 'styled-components';
import { useCallback } from 'react';

import WorkspaceTreeNode from './WorkspaceTreeNode';
import Icon from './Icon';
import { useWorkspaces } from '../provider/WorkspaceProvider';

const Table = styled.table`
    margin-top: 1em;
    border-spacing: 0;
    position: relative;
    width: 100%;
`;

const HeaderColumn = styled.th`
    padding: 0.5em;
    position: sticky;
    top: 40px;
    user-select: none;
    background: var(--grayDark);
`;

const IconButton = styled.button`
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    cursor: pointer;
    outline: none;
    color: var(--textOnGray);
`;

export enum SortBy {
    title,
    lastModified,
}

const WorkspaceTable: React.FC = () => {
    const { sorting, setSorting } = useWorkspaces();

    const handleSortByTitle = useCallback(() => {
        setSorting(SortBy.title);
    }, []);

    const handleSortByLastModified = useCallback(() => {
        setSorting(SortBy.lastModified);
    }, []);

    return (
        <Table>
            <thead>
                <tr>
                    <HeaderColumn> </HeaderColumn>
                    <HeaderColumn>
                        <IconButton
                            type="button"
                            onClick={handleSortByTitle}
                            style={sorting === SortBy.title ? { color: 'var(--blue)' } : {}}
                        >
                            Title <Icon icon="sort-alpha-down" />
                        </IconButton>
                    </HeaderColumn>
                    <HeaderColumn>Description</HeaderColumn>
                    <HeaderColumn>Base workspace</HeaderColumn>
                    <HeaderColumn>Creator</HeaderColumn>
                    <HeaderColumn>
                        <IconButton
                            type="button"
                            onClick={handleSortByLastModified}
                            style={sorting === SortBy.lastModified ? { color: 'var(--blue)' } : {}}
                        >
                            Last modified <Icon icon="sort" />
                        </IconButton>
                    </HeaderColumn>
                    <HeaderColumn>Changes</HeaderColumn>
                    <HeaderColumn>Actions</HeaderColumn>
                </tr>
            </thead>
            <tbody>
                <WorkspaceTreeNode workspaceName="live" />
            </tbody>
        </Table>
    );
};

export default React.memo(WorkspaceTable);
