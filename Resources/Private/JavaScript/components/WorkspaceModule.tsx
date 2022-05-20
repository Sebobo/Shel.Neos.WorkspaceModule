import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';
import { DeleteWorkspaceDialog, EditWorkspaceDialog, CreateWorkspaceDialog } from './dialogs';
import Footer from './Footer';

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
