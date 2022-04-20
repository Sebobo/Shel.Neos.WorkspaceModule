interface NeosI18n {
    translate: (
        id: string,
        fallback: string,
        packageKey: string,
        source: string,
        args: Record<string, unknown> | string[]
    ) => string;
    initialized: boolean;
}

type TranslateFunction = (
    id: string,
    fallback?: string,
    parameters?: Record<string, string | number> | string[]
) => string;

interface NeosNotification {
    notice: (title: string) => void;
    ok: (title: string) => void;
    error: (title: string, message?: string) => void;
    warning: (title: string, message?: string) => void;
    info: (title: string) => void;
}

interface Window {
    NeosCMS: {
        I18n: NeosI18n;
        Notification: NeosNotification;
    };
}

type ActionUri = string;
type UserName = string;
type WorkspaceName = string;

type ChangesCounts = {
    new: number;
    changed: number;
    removed: number;
    total: number;
};

type WorkspaceEndpoints = {
    deleteWorkspace: ActionUri; // Delete a workspace
    forceDeleteWorkspace: ActionUri; // Force deletes a workspace including its changes and rebase dependent workspaces
    updateWorkspace: ActionUri; // Show edit dialog
    editWorkspace: ActionUri; // Update a workspace
    newWorkspace: ActionUri; // Show dialog to create new workspace
    createWorkspace: ActionUri; // Create new workspace
    showWorkspace: ActionUri; // Show changes in workspace
    getChanges: ActionUri; // Load number of changes for all workspaces
};

interface Workspace {
    name: WorkspaceName;
    title: string;
    description: string | null;
    owner: UserName | null;
    creator: UserName | null;
    lastChangedDate: number | null;
    lastChangedTimestamp: number | null;
    lastChangedBy: UserName | null;
    baseWorkspace: {
        name: WorkspaceName;
        title: string;
    } | null;
    nodeCount: number;
    changesCounts: ChangesCounts | null;
    isInternal: boolean;
    isStale: boolean;
    canPublish: boolean;
    canManage: boolean;
    dependentWorkspacesCount: number;
}

type WorkspaceList = Record<WorkspaceName, Workspace>;
