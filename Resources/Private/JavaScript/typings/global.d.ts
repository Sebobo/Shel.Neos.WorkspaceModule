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

type FlashMessage = {
    title: string;
    message: string;
    severity: 'error' | 'info' | 'ok' | 'warning';
};

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

type WorkspaceValidation = {
    titlePattern: string;
};

type ActionUri = string;
type UserName = string;
type UserLabel = string;
type WorkspaceName = string;
type WorkspaceTitle = string;

type ChangesCounts = {
    new: number;
    changed: number;
    removed: number;
    total: number;
};

type WorkspaceEndpoints = {
    deleteWorkspace: ActionUri; // Delete a workspace
    updateWorkspace: ActionUri; // Show edit dialog
    createWorkspace: ActionUri; // Create new workspace
    showWorkspace: ActionUri; // Show changes in workspace
    getChanges: ActionUri; // Load number of changes for all workspaces
};

interface Workspace {
    name: WorkspaceName;
    title: WorkspaceTitle;
    description: string | null;
    owner: UserName | null;
    creator: UserName | null;
    lastChangedDate: number | null;
    lastChangedTimestamp: number | null;
    lastChangedBy: UserName | null;
    baseWorkspace: {
        name: WorkspaceName;
        title: WorkspaceTitle;
    } | null;
    nodeCount: number;
    changesCounts: ChangesCounts | null;
    isPersonal: boolean;
    isInternal: boolean;
    isStale: boolean;
    canPublish: boolean;
    canManage: boolean;
    dependentWorkspacesCount: number;
}

type WorkspaceList = Record<WorkspaceName, Workspace>;
type BaseWorkspaceOptions = Record<WorkspaceName, WorkspaceTitle>;
