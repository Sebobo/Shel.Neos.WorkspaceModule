import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';
import DeleteWorkspaceDialog from './dialogs/DeleteWorkspaceDialog';
import EditWorkspaceDialog from './dialogs/EditWorkspaceDialog';
import Footer from './Footer';
import CreateWorkspaceDialog from './dialogs/CreateWorkspaceDialog';

const WorkspaceModule: React.FC = () => {
    return (
        <ErrorBoundary>
            <WorkspaceTable />
            <Footer />
            <DeleteWorkspaceDialog />
            <EditWorkspaceDialog />
            <CreateWorkspaceDialog />
        </ErrorBoundary>
    );
};

export default React.memo(WorkspaceModule);
