import React from 'react';

import WorkspaceTable from './WorkspaceTable';
import { DeleteWorkspaceDialog, EditWorkspaceDialog, CreateWorkspaceDialog, PruneWorkspaceDialog } from './dialogs';
import Footer from './Footer';
import NaggingScreen from './NaggingScreen';

const WorkspaceModule: React.FC = () => {
    return (
        <>
            <NaggingScreen />
            <WorkspaceTable />
            <Footer />
            <DeleteWorkspaceDialog />
            <PruneWorkspaceDialog />
            <EditWorkspaceDialog />
            <CreateWorkspaceDialog />
        </>
    );
};

export default React.memo(WorkspaceModule);
