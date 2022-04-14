import React, { createContext, ReactNode, useCallback, useContext, useEffect, useState } from 'react';

import { SortBy } from '../components/WorkspaceTable';

type WorkspaceProviderProps = {
    value: {
        userWorkspace: WorkspaceName;
        workspaces: WorkspaceList;
        endpoints: WorkspaceEndpoints;
    };
    children: ReactNode;
};

type WorkspaceValues = {
    userWorkspace: WorkspaceName;
    workspaces: WorkspaceList;
    setWorkspaces: (workspaces: WorkspaceList) => void;
    loadChangesCounts: () => void;
    deleteWorkspace: (workspaceName: WorkspaceName) => void;
    editWorkspace: (workspaceName: WorkspaceName) => void;
    sorting: SortBy;
    setSorting: (sortBy: SortBy) => void;
};

const WorkspaceContext = createContext(null);
export const useWorkspaces = (): WorkspaceValues => useContext(WorkspaceContext);

export const WorkspaceProvider = ({ value, children }: WorkspaceProviderProps) => {
    const { userWorkspace, endpoints } = value;
    const [workspaces, setWorkspaces] = React.useState(value.workspaces);
    const [sorting, setSorting] = useState<SortBy>(SortBy.title);

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
                console.error('Failed to load changes for workspaces', error);
            });
    }, [endpoints]);

    const deleteWorkspace = useCallback(() => {
        console.debug('Delete workspace');
    }, []);
    const editWorkspace = useCallback(() => {
        console.debug('Edit workspace');
    }, []);

    useEffect(() => {
        loadChangesCounts();
    }, [value.workspaces]);

    return (
        <WorkspaceContext.Provider
            value={{
                userWorkspace,
                workspaces,
                setWorkspaces,
                loadChangesCounts,
                deleteWorkspace,
                editWorkspace,
                sorting,
                setSorting,
            }}
        >
            {children}
        </WorkspaceContext.Provider>
    );
};
