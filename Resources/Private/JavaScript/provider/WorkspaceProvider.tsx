import React, { createContext, ReactNode, useCallback, useContext, useEffect, useState } from 'react';

import { SortBy } from '../components/WorkspaceTable';
import { useNotify } from './NotifyProvider';

type WorkspaceProviderProps = {
    children: ReactNode;
    userWorkspace: WorkspaceName;
    workspaceList: WorkspaceList;
    endpoints: WorkspaceEndpoints;
    csrfToken: string;
};

type WorkspaceValues = {
    userWorkspace: WorkspaceName;
    workspaces: WorkspaceList;
    setWorkspaces: (workspaces: WorkspaceList) => void;
    loadChangesCounts: () => void;
    deleteWorkspace: (workspaceName: WorkspaceName) => void;
    updateWorkspace: (formData: FormData) => Promise<void>;
    showWorkspace: (workspaceName: WorkspaceName) => void;
    sorting: SortBy;
    setSorting: (sortBy: SortBy) => void;
    selectedWorkspaceForDeletion: WorkspaceName | null;
    setSelectedWorkspaceForDeletion: (workspaceName: WorkspaceName | null) => void;
    selectedWorkspaceForEdit: WorkspaceName | null;
    setSelectedWorkspaceForEdit: (workspaceName: WorkspaceName | null) => void;
    csrfToken: string;
};

const WorkspaceContext = createContext(null);
export const useWorkspaces = (): WorkspaceValues => useContext(WorkspaceContext);

export const WorkspaceProvider = ({
    userWorkspace,
    endpoints,
    workspaceList,
    csrfToken,
    children,
}: WorkspaceProviderProps) => {
    const [workspaces, setWorkspaces] = React.useState(workspaceList);
    const [sorting, setSorting] = useState<SortBy>(SortBy.lastModified);
    const [selectedWorkspaceForDeletion, setSelectedWorkspaceForDeletion] = useState<WorkspaceName | null>(null);
    const [selectedWorkspaceForEdit, setSelectedWorkspaceForEdit] = useState<WorkspaceName | null>(null);
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
        // FIXME: Must be a post action
        window.open(prepareWorkspaceActionUrl(endpoints.deleteWorkspace, workspaceName), '_self');
    }, []);

    const updateWorkspace = useCallback(
        async (formData: FormData): Promise<void> => {
            return fetch(endpoints.updateWorkspace, {
                method: 'POST',
                credentials: 'include',
                body: formData,
            })
                .then((response) => response.json())
                .then((workspace: Workspace) => {
                    setWorkspaces({ ...workspaces, [workspace.name]: { ...workspaces[workspace.name], ...workspace } });
                    notify.ok('Workspace updated');
                    return workspace[workspace.name];
                })
                .catch((error) => {
                    notify.error('Failed to update workspace', error.message);
                    console.error('Failed to update workspace', error);
                });
        },
        [csrfToken, endpoints.updateWorkspace]
    );

    const showWorkspace = useCallback((workspaceName: string) => {
        window.open(prepareWorkspaceActionUrl(endpoints.showWorkspace, workspaceName), '_self');
    }, []);

    useEffect(() => {
        if (workspaceList) loadChangesCounts();
    }, []);

    return (
        <WorkspaceContext.Provider
            value={{
                userWorkspace,
                workspaces,
                setWorkspaces,
                loadChangesCounts,
                deleteWorkspace,
                updateWorkspace,
                showWorkspace,
                sorting,
                setSorting,
                selectedWorkspaceForDeletion,
                setSelectedWorkspaceForDeletion,
                selectedWorkspaceForEdit,
                setSelectedWorkspaceForEdit,
                csrfToken,
            }}
        >
            {children}
        </WorkspaceContext.Provider>
    );
};
