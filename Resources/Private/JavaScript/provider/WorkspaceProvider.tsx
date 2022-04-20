import React, { createContext, ReactNode, useCallback, useContext, useEffect, useState } from 'react';

import { SortBy } from '../components/WorkspaceTable';
import { useNotify } from './NotifyProvider';

type WorkspaceProviderProps = {
    children: ReactNode;
    userWorkspace: WorkspaceName;
    workspaceList: WorkspaceList;
    endpoints: WorkspaceEndpoints;
};

type WorkspaceValues = {
    userWorkspace: WorkspaceName;
    workspaces: WorkspaceList;
    setWorkspaces: (workspaces: WorkspaceList) => void;
    loadChangesCounts: () => void;
    deleteWorkspace: (workspaceName: WorkspaceName) => void;
    editWorkspace: (workspaceName: WorkspaceName) => void;
    showWorkspace: (workspaceName: WorkspaceName) => void;
    sorting: SortBy;
    setSorting: (sortBy: SortBy) => void;
    selectedWorkspaceForDeletion: WorkspaceName | null;
    setSelectedWorkspaceForDeletion: (workspaceName: WorkspaceName | null) => void;
};

const WorkspaceContext = createContext(null);
export const useWorkspaces = (): WorkspaceValues => useContext(WorkspaceContext);

export const WorkspaceProvider = ({ userWorkspace, endpoints, workspaceList, children }: WorkspaceProviderProps) => {
    const [workspaces, setWorkspaces] = React.useState(workspaceList);
    const [sorting, setSorting] = useState<SortBy>(SortBy.title);
    const [selectedWorkspaceForDeletion, setSelectedWorkspaceForDeletion] = useState<WorkspaceName | null>(null);
    const notify = useNotify();

    const loadChangesCounts = useCallback(() => {
        if (!workspaces) return;
        fetch(endpoints.getChanges, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json; charset=UTF-8',
            },
        })
            .then((response) => response.json())
            .then((data) => {
                const { changesByWorkspace }: { changesByWorkspace: Record<WorkspaceName, ChangesCounts> } = data;
                const updatedWorkspaces = Object.keys(workspaces).reduce<WorkspaceList>(
                    (carry: WorkspaceList, workspaceName) => {
                        const changesCounts = changesByWorkspace[workspaceName];
                        if (changesCounts) {
                            carry[workspaceName] = { ...workspaces[workspaceName], changesCounts };
                        } else {
                            carry[workspaceName] = workspaces[workspaceName];
                        }
                        return carry;
                    },
                    {} as WorkspaceList
                );
                setWorkspaces(updatedWorkspaces);
            })
            .catch((error) => {
                notify.error('Failed to load changes for workspaces', error.message);
                console.error('Failed to load changes for workspaces', error);
            });
    }, [endpoints]);

    const prepareWorkspaceActionUrl = useCallback((endpoint: string, workspaceName: WorkspaceName) => {
        return endpoint.replace('---workspace---', workspaceName);
    }, []);

    const deleteWorkspace = useCallback((workspaceName: string) => {
        window.open(prepareWorkspaceActionUrl(endpoints.forceDeleteWorkspace, workspaceName), '_self');
    }, []);

    const editWorkspace = useCallback((workspaceName: string) => {
        window.open(prepareWorkspaceActionUrl(endpoints.editWorkspace, workspaceName), '_self');
    }, []);

    const showWorkspace = useCallback((workspaceName: string) => {
        window.open(prepareWorkspaceActionUrl(endpoints.showWorkspace, workspaceName), '_self');
    }, []);

    useEffect(() => {
        loadChangesCounts();
    }, [workspaceList]);

    return (
        <WorkspaceContext.Provider
            value={{
                userWorkspace,
                workspaces,
                setWorkspaces,
                loadChangesCounts,
                deleteWorkspace,
                editWorkspace,
                showWorkspace,
                sorting,
                setSorting,
                selectedWorkspaceForDeletion,
                setSelectedWorkspaceForDeletion,
            }}
        >
            {children}
        </WorkspaceContext.Provider>
    );
};
