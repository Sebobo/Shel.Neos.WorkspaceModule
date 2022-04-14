import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';

type WorkspaceModuleProps = {};

const WorkspaceModule: React.FC<WorkspaceModuleProps> = ({}) => {
    return (
        <ErrorBoundary>
            <WorkspaceTable />
        </ErrorBoundary>
    );
};

export default React.memo(WorkspaceModule);
