import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';
import DeleteWorkspaceDialog from './dialogs/DeleteWorkspaceDialog';

const WorkspaceModule: React.FC = () => {
    return (
        <ErrorBoundary>
            <WorkspaceTable />
            <DeleteWorkspaceDialog />
        </ErrorBoundary>
    );
};

export default React.memo(WorkspaceModule);
