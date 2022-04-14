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
    updateWorkspace: ActionUri; // Show edit dialog
    editWorkspace: ActionUri; // Update a workspace
    newWorkspace: ActionUri; // Show dialog to create new workspace
    createWorkspace: ActionUri; // Create new workspace
    getChanges: ActionUri; // Load number of changes for all workspaces
};

interface Workspace {
    name: WorkspaceName;
    title: string;
    description: string | null;
    owner: UserName | null;
    creator: UserName | null;
    lastModifiedDate: number | null;
    lastModifiedBy: UserName | null;
    baseWorkspace: {
        name: WorkspaceName;
        title: string;
    } | null;
    changesCounts: ChangesCounts | null;
    isInternal: boolean;
    canPublish: boolean;
    canManage: boolean;
    dependentWorkspacesCount: number;
}

type WorkspaceList = Record<WorkspaceName, Workspace>;
