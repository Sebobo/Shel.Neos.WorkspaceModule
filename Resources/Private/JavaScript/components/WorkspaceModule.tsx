import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';
import DeleteWorkspaceDialog from './dialogs/DeleteWorkspaceDialog';
import EditWorkspaceDialog from './dialogs/EditWorkspaceDialog';

const WorkspaceModule: React.FC = () => {
    return (
        <ErrorBoundary>
            <WorkspaceTable />
            <DeleteWorkspaceDialog />
            <EditWorkspaceDialog />
        </ErrorBoundary>
    );
};

export default React.memo(WorkspaceModule);
